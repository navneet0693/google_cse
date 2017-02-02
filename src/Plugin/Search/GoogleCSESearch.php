<?php

namespace Drupal\google_cse\Plugin\Search;

use Drupal\search\Plugin\ConfigurableSearchPluginBase;
use Drupal\Core\Access\AccessibleInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\google_cse\GoogleCSEServices;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles searching for node entities using the Search module index.
 *
 * @SearchPlugin(
 *   id = "google_cse_search",
 *   title = @Translation("Google CSE Search")
 * )
 */
class GoogleCSESearch extends ConfigurableSearchPluginBase implements AccessibleInterface {

  protected $googlecseservices;

  protected $configuration;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, GoogleCSEServices $googlecseservices) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->googlecseservices = $googlecseservices;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('google_cse.services')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setSearch($keywords, array $parameters, array $attributes) {
    if (empty($parameters['search_conditions'])) {
      $parameters['search_conditions'] = '';
    }
    parent::setSearch($keywords, $parameters, $attributes);
  }

  /**
   * {@inheritdoc}
   */
  public function access($operation = 'view', AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = AccessResult::allowedIf(!empty($account) && $account->hasPermission('search Google CSE'))->cachePerPermissions();
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $configuration = [
      'cx' => '',
      'results_tab' => '',
      'results_width' => 600,
      'cof_here' => 'FORID:11',
      'cof_google' => 'FORID:0',
      'results_prefix' => '',
      'results_suffix' => '',
      'results_searchbox_width' => 40,
      'results_display' => 'here',
      'results_display_images' => TRUE,
      'sitesearch' => '',
      'sitesearch_form' => 'radios',
      'sitesearch_option' => '',
      'sitesearch_default' => 0,
      'domain' => 'www.google.com',
      'limit_domain' => '',
      'cr' => '',
      'gl' => '',
      'hl' => '',
      'locale_hl' => '',
      'ie' => 'utf-8',
      'lr' => '',
      'locale_lr' => '',
      'oe' => '',
      'safe' => '',
      'custom_css' => '',
      'custom_results_display' => 'results-only',
      'use_adv' => 0
    ];
    return $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = $configuration;
  }

  /**
   * Verifies if the given parameters are valid enough to execute a search for.
   *
   * @return bool
   *   TRUE if there are keywords or search conditions in the query.
   */
  public function isSearchExecutable() {
    return (bool) ($this->keywords || !empty($this->searchParameters['search_conditions']));
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $keys = $this->getKeywords();
    // @todo $condition is an unused variable verify and remove it.
    $conditions = $this->searchParameters['search_conditions'];
    if ($this->configuration['use_adv']) {
      $response = $this->googlecseservices->service($keys);
      $results = $this->googlecseservices->responseResults($response[0], $keys, $conditions);

      // Allow other modules to alter the keys.
      \Drupal::moduleHandler()->alter('google_cse_searched_keys', $keys);

      // Allow other modules to alter the results.
      \Drupal::moduleHandler()->alter('google_cse_searched_results', $results);

      return $results;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildResults() {
    $results = $this->execute();

    // @see https://www.drupal.org/node/2195739
    if (!(\Drupal::config('googlcse.settings')->get('configuration')['use_adv'])) {
      $output[] = ['#theme' => 'google_cse_results'];
      return $output;
    }

    if (!$results) {
      // No results found.
      $output[] = ['#theme' => 'google_cse_search_noresults'];
    }

    if (!empty($_GET['page'])) {
      $current_page = $_GET['page'];
      $number_results = t('Results @from to @to of @total matches.', array(
        '@from' => $current_page * 10,
        '@to' => $current_page * 10 + 10,
        '@total' => $GLOBALS['pager_total_items'][0],
      ));
      $output['prefix']['#markup'] = $number_results . '<ol class="search-results">';
    }

    foreach ($results as $entry) {
      $output[] = [
        '#theme' => 'search_result',
        '#result' => $entry,
        '#plugin_id' => $this->getPluginId(),
      ];
    }

    if (!empty($_GET['page'])) {
      // Important, add the pager.
      $pager = ['#type' => 'pager'];
      $output['suffix']['#markup'] = '</ol>' . \Drupal::service('renderer')->render($pager);
    }

    return $output;
  }

  /**
   * {@inheritdoc}
   *
   * Adds custom submit handler for search form.
   */
  public function searchFormAlter(array &$form, FormStateInterface $form_state) {
    if ($this->pluginId == 'google_cse_search') {
      $this->googlecseservices->siteSearchForm($form);
      $form['#attributes']['class'][] = 'google-cse';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildSearchUrlQuery(FormStateInterface $form_state) {
    // Read keyword and advanced search information from the form values,
    // and put these into the GET parameters.
    $keys = trim($form_state->getValue('keys'));
    if (!\Drupal::config('search.page.google_cse_search')->get('configuration')['use_adv']) {
      return ['query' => $keys];
    }
    // @TODO check usage of $here and $sitesearch
    $sitesearch = NULL;
    $here = FALSE;
    return [
        'query' => $keys,
        'cx' => \Drupal::config('search.page.google_cse_search')->get('configuration')['cx'],
        'cof' => $here ? \Drupal::config('search.page.google_cse_search')->get('configuration')['cof_here'] : \Drupal::config('search.page.google_cse_search')->get('configuration')['cof_google'],
        'sitesearch' => isset($sitesearch) ? $sitesearch : $this->googlecseservices->sitesearchDefault(),
      ] + $this->googlecseservices->advancedSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $form['google_cse'] = [
      '#title' => $this->t('Google CSE'),
      '#type' => 'fieldset',
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];

    $form['google_cse']['cx'] = [
      '#title' => $this->t('Google Custom Search Engine ID'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['cx'],
      '#description' => $this->t('Enter your <a target="_blank" href="http://www.google.com/cse/manage/all">Google CSE unique ID</a> (click on control panel).'),
    ];

    $form['google_cse']['results_tab'] = [
      '#title' => $this->t('Search results tab name'),
      '#type' => 'textfield',
      '#maxlength' => 50,
      '#size' => 60,
      '#description' => $this->t('Enter a custom name of the tab where search results are displayed (defaults to %google).', [
        '%google' => $this->t('Google')
      ]),
      '#default_value' => $this->configuration['results_tab'],
    ];

    $form['google_cse']['results_width'] = [
      '#title' => $this->t('Search results frame width'),
      '#type' => 'textfield',
      '#maxlength' => 4,
      '#size' => 6,
      '#description' => $this->t('Enter the desired width, in pixels, of the search frame.'),
      '#default_value' => $this->configuration['results_width'],
    ];

    $form['google_cse']['cof_here'] = [
      '#title' => $this->t('Ad format on this site'),
      '#type' => 'radios',
      '#default_value' => $this->configuration['cof_here'],
      '#options' => [
        'FORID:9' => $this->t('Right'),
        'FORID:10' => $this->t('Top and right'),
        'FORID:11' => $this->t('Top and bottom'),
      ],
      '#description' => $this->t('Ads on the right increase the width of the iframe. Non-profit organizations can disable ads in the Google CSE control panel.'),
    ];

    $form['google_cse']['cof_google'] = [
      '#title' => $this->t('Ad format on Google'),
      '#type' => 'radios',
      '#default_value' => $this->configuration['cof_google'],
      '#options' => [
        'FORID:0' => $this->t('Right'),
        'FORID:1' => $this->t('Top and bottom'),
      ],
      '#description' => $this->t('AdSense ads are also displayed when the CSE links or redirects to Google.'),
    ];

    $form['google_cse']['results_prefix'] = [
      '#title' => $this->t('Search results prefix text'),
      '#type' => 'textarea',
      '#cols' => 50,
      '#rows' => 4,
      '#description' => $this->t('Enter text to appear on the search page before the search form.'),
      '#default_value' => $this->configuration['results_prefix'],
    ];

    $form['google_cse']['results_suffix'] = [
      '#title' => $this->t('Search results suffix text'),
      '#type' => 'textarea',
      '#cols' => 50,
      '#rows' => 4,
      '#description' => $this->t('Enter text to appear on the search page after the search form and results.'),
      '#default_value' => $this->configuration['results_suffix'],
    ];

    $form['google_cse']['results_searchbox_width'] = [
      '#title' => $this->t('Google CSE block searchbox width'),
      '#type' => 'textfield',
      '#maxlength' => 4,
      '#size' => 6,
      '#description' => $this->t('Enter the desired width, in characters, of the searchbox on the Google CSE block.'),
      '#default_value' => $this->configuration['results_searchbox_width'],
    ];

    $form['google_cse']['results_display'] = [
      '#title' => $this->t('Display search results'),
      '#type' => 'radios',
      '#default_value' => $this->configuration['results_display'],
      '#options' => [
        'here' => $this->t('On this site (requires JavaScript)'),
        'google' => $this->t('On Google'),
      ],
      '#description' => $this->t('Search results for the Google CSE block can be displayed on this site, using JavaScript, or on Google, which does not require JavaScript.'),
    ];

    $form['google_cse']['results_display_images'] = [
      '#title' => $this->t('Display thumbnail images in the search results'),
      '#type' => 'checkbox',
      '#description' => $this->t('If set, search result snippets will contain a thumbnail image'),
      '#default_value' => $this->configuration['results_display_images'],
    ];

    $form['google_cse']['sitesearch'] = [
      '#title' => $this->t('SiteSearch settings'),
      '#type' => 'fieldset',
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['google_cse']['sitesearch']['google_cse_sitesearch'] = [
      '#title' => $this->t('SiteSearch domain'),
      '#type' => 'textarea',
      '#cols' => 50,
      '#rows' => 4,
      '#description' => $this->t('If set, users will be presented with the option of searching only on the domain(s) specified rather than using the CSE. Enter one domain or URL path followed by a description (e.g. <em>example.com/user Search users</em>) on each line.'),
      '#default_value' => $this->configuration['google_cse_sitesearch'],
    ];

    $form['google_cse']['sitesearch']['sitesearch_form'] = [
      '#title' => $this->t('SiteSearch form element'),
      '#type' => 'radios',
      '#options' => [
        'radios' => $this->t('Radio buttons'),
        'select' => $this->t('Select'),
      ],
      '#description' => $this->t('Select the type of form element used to present the SiteSearch option(s).'),
      '#default_value' => $this->configuration['sitesearch_form'],
    ];

    $form['google_cse']['sitesearch']['sitesearch_option'] = [
      '#title' => $this->t('CSE search option label'),
      '#type' => 'textfield',
      '#maxlength' => 50,
      '#size' => 60,
      '#description' => $this->t('Customize the label for CSE search if SiteSearch is enabled (defaults to %search-web).', [
        '%search-web' => t('Search the web')
      ]),
      '#default_value' => $this->configuration['sitesearch_option'],
    ];

    $form['google_cse']['sitesearch']['sitesearch_default'] = [
      '#title' => $this->t('Default to using the SiteSearch domain'),
      '#type' => 'checkbox',
      '#description' => $this->t('If set, searches will default to using the first listed SiteSearch domain rather than the CSE.'),
      '#default_value' => $this->configuration['sitesearch_default'],
    ];

    $form['google_cse']['advanced'] = [
      '#title' => $this->t('Advanced settings'),
      '#type' => 'fieldset',
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['google_cse']['advanced']['domain'] = [
      '#title' => $this->t('Search domain'),
      '#type' => 'textfield',
      '#maxlength' => 64,
      '#description' => $this->t('Enter the Google domain to use for search results, e.g. <em>www.google.com</em>.'),
      '#default_value' => $this->configuration['domain'],
    ];

    $form['google_cse']['advanced']['limit_domain'] = [
      '#title' => $this->t('Limit results to this domain'),
      '#type' => 'textfield',
      '#maxlength' => 64,
      '#description' => $this->t('Enter the domain to limit results on
      (only display results for this domain) <em>www.google.com</em>.'),
      '#default_value' => $this->configuration['limit_domain'],
    ];

    $form['google_cse']['advanced']['cr'] = [
      '#title' => $this->t('Country restriction'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['cr'],
      '#description' => $this->t('Enter a 9-letter country code, e.g. <em>countryNZ</em>, and optional boolean operators, to restrict search results to documents (not) originating in particular countries. See the <a target="_blank" href="https://developers.google.com/custom-search/docs/xml_results#crsp"><em>cr</em> parameter</a>.'),
    ];

    $form['google_cse']['advanced']['gl'] = [
      '#title' => $this->t('Country boost'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['gl'],
      '#description' => $this->t('Enter a 2-letter country code, e.g. <em>uk</em>, to boost documents written in a particular country. See the <a target="_blank" href="https://developers.google.com/custom-search/docs/xml_results#glsp"><em>gl</em> parameter</a>.'),
    ];

    $form['google_cse']['advanced']['hl'] = [
      '#title' => $this->t('Interface language'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['hl'],
      '#description' => $this->t('Enter a supported 2- or 5-character language code, e.g. <em>fr</em>, to set the language of the user interface. See the <a target="_blank" href="https://developers.google.com/custom-search/docs/xml_results#hlsp"><em>hl</em> parameter</a>.'),
    ];

    $form['google_cse']['advanced']['locale_hl'] = [
      '#title' => $this->t('Set interface language dynamically'),
      '#type' => 'checkbox',
      '#default_value' => $this->configuration['locale_hl'],
      '#description' => $this->t('The language restriction can be set dynamically if the locale module is enabled. Note the locale language code must match one of the <a target="_blank" href="https://developers.google.com/custom-search/docs/xml_results#interfaceLanguages">supported language codes</a>.'),
    ];

    $form['google_cse']['advanced']['ie'] = [
      '#title' => $this->t('Input encoding'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['ie'],
      '#description' => $this->t('The default <em>utf-8</em> is recommended. See the <a target="_blank" href="https://developers.google.com/custom-search/docs/xml_results#iesp"><em>ie</em> parameter</a>.'),
    ];

    $form['google_cse']['advanced']['lr'] = [
      '#title' => $this->t('Language restriction'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['lr'],
      '#description' => $this->t('Enter a supported 7- or 10-character language code, e.g. <em>lang_en</em>, and optional boolean operators, to restrict search results to documents (not) written in particular languages. See the <a target="_blank" href="https://developers.google.com/custom-search/docs/xml_results#lrsp"><em>lr</em> parameter</a>.'),
    ];

    $form['google_cse']['advanced']['locale_lr'] = [
      '#title' => $this->t('Set language restriction dynamically'),
      '#type' => 'checkbox',
      '#default_value' => $this->configuration['locale_lr'],
      '#description' => $this->t('The language restriction can be set dynamically if the locale module is enabled. Note the locale language code must match one of the <a target="_blank" href="https://developers.google.com/custom-search/docs/xml_results#languageCollections">supported language codes</a>.'),
    ];

    $form['google_cse']['advanced']['oe'] = [
      '#title' => $this->t('Output encoding'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['oe'],
      '#description' => $this->t('The default <em>utf-8</em> is recommended. See the <a target="_blank" href="https://developers.google.com/custom-search/docs/xml_results#oesp"><em>oe</em> parameter</a>.'),
    ];

    $form['google_cse']['advanced']['safe'] = [
      '#title' => $this->t('SafeSearch filter'),
      '#type' => 'select',
      '#options' => [
        '' => '',
        'off' => $this->t('Off'),
        'medium' => $this->t('Medium'),
        'high' => $this->t('High'),
      ],
      '#default_value' => $this->configuration['safe'],
      '#description' => $this->t('SafeSearch filters search results for adult content. See the <a target="_blank" href="https://developers.google.com/custom-search/docs/xml_results#safesp"><em>safe</em> parameter</a>.'),
    ];

    $form['google_cse']['advanced']['custom_css'] = [
      '#title' => t('Stylesheet Override'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['custom_css'],
      '#description' => $this->t('Set a custom stylesheet to override or add any styles not allowed in the CSE settings (such as "background-color: none;"). Include <span style="color:red; font-weight:bold;">!important</span> for overrides.<br/>Example: <em>//replacewithrealsite.com/sites/all/modules/google_cse/default.css</em>'),
    ];

    $form['google_cse']['advanced']['custom_results_display'] = [
      '#title' => $this->t('Layout of Search Engine'),
      '#type' => 'radios',
      '#default_value' => $this->configuration['custom_results_display'],
      '#options' => [
        'overlay' => $this->t('Overlay'),
        'two-page' => $this->t('Two page'),
        'full-width' => $this->t('Full width'),
        'two-column' => $this->t('Two column'),
        'compact' => $this->t('Compact'),
        'results-only' => $this->t('Results only'),
        'google-hosted' => $this->t('Google hosted'),
      ],
      '#description' => $this->t('Set the search engine layout, as found in the Layout tab of <a target="_blank" href="@url">Custom Search settings</a>.', [
        '@url' => 'https://www.google.com/cse/lookandfeel/layout?cx=' . $this->configuration['cx'],
      ]),
    ];

    $form['google_cse_adv'] = [
      '#title' => $this->t('Google CSE Advanced'),
      '#type' => 'fieldset',
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];

    $form['google_cse_adv']['use_adv'] = [
      '#title' => t('Use advanced, ad-free version, search engine (You will need a paid account with Google)'),
      '#type' => 'checkbox',
      '#default_value' => $this->configuration['use_adv'],
      '#description' => $this->t('If enabled, search results will be fetch using Adv engine.'),
    ];
    
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['cx'] = $form_state->getValue('cx');
    $this->configuration['results_tab'] = $form_state->getValue('results_tab');
    $this->configuration['results_width'] = $form_state->getValue('results_width');
    $this->configuration['cof_here'] = $form_state->getValue('cof_here');
    $this->configuration['cof_google'] = $form_state->getValue('cof_google');
    $this->configuration['results_prefix'] = $form_state->getValue('results_prefix');
    $this->configuration['results_suffix'] = $form_state->getValue('results_suffix');
    $this->configuration['results_searchbox_width'] = $form_state->getValue('results_searchbox_width');
    $this->configuration['results_display'] = $form_state->getValue('results_display');
    $this->configuration['results_display_images'] = $form_state->getValue('results_display_images');
    $this->configuration['google_cse_sitesearch'] = $form_state->getValue('google_cse_sitesearch');
    $this->configuration['sitesearch_form'] = $form_state->getValue('sitesearch_form');
    $this->configuration['sitesearch_option'] = $form_state->getValue('sitesearch_option');
    $this->configuration['sitesearch_default'] = $form_state->getValue('sitesearch_default');
    $this->configuration['domain'] = $form_state->getValue('domain');
    $this->configuration['limit_domain'] = $form_state->getValue('limit_domain');
    $this->configuration['cr'] = $form_state->getValue('cr');
    $this->configuration['gl'] = $form_state->getValue('gl');
    $this->configuration['hl'] = $form_state->getValue('hl');
    $this->configuration['locale_hl'] = $form_state->getValue('locale_hl');
    $this->configuration['ie'] = $form_state->getValue('ie');
    $this->configuration['lr'] = $form_state->getValue('lr');
    $this->configuration['locale_lr'] = $form_state->getValue('locale_lr');
    $this->configuration['oe'] = $form_state->getValue('oe');
    $this->configuration['safe'] = $form_state->getValue('safe');
    $this->configuration['custom_css'] = $form_state->getValue('custom_css');
    $this->configuration['custom_results_display'] = $form_state->getValue('custom_results_display');
    $this->configuration['use_adv'] = $form_state->getValue('use_adv');

  }

}
