<?php

namespace Drupal\google_cse\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\google_cse\GoogleCSEServices;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class GoogleCSESearchBoxForm
 * @package Drupal\google_cse\Form
 *
 * Form builder for the searchbox forms.
 */
class GoogleCSESearchBoxForm extends FormBase {

  /**
   * @var \Drupal\google_cse\GoogleCSEServices $googleCSEServices
   */
  protected $googleCSEServices;

  /**
   * GoogleCSESearchBoxForm constructor.
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   * @param \Drupal\google_cse\GoogleCSEServices $googleCSEServices
   */
  public function __construct(GoogleCSEServices $googleCSEServices) {
    $this->googleCSEServices = $googleCSEServices;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('google_cse.services')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_cse_search_box_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('search.page.google_cse_search');
    if ($config->get('configuration')['results_display'] == 'here') {
      $cof = $config->get('configuration')['cof_here'];
    }
    else {
      $form['#action'] = 'http://' . $config->get('configuration')['domain'] . '/cse';
      $cof = $config->get('configuration')['cof_google'];
    }
    $form['#method'] = 'get';
    $form['cx'] = [
      '#type' => 'hidden',
      '#value' => $config->get('configuration')['cx'],
    ];
    $form['cof'] = [
      '#type' => 'hidden',
      '#value' => $cof,
    ];
    $form['query'] = [
      '#type' => 'textfield',
      '#default_value' => isset($_GET['query']) ? $_GET['query'] : '',
    ];
    $form['sa'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
    ];
    foreach ($this->googleCSEServices->advancedSettings() as $parameter => $setting) {
      $form[$parameter] = [
        '#type' => 'hidden',
        '#value' => $setting,
      ];
    }
    $form['query']['#size'] = intval($config->get('configuration')['results_searchbox_width']);
    $form['query']['#title'] = $this->t('Enter your keywords');
    $this->googleCSEServices->siteSearchForm($form);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // We leave it blank intentionally.
  }

}
