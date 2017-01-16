<?php

/**
 * @file
 * Themeable functions for Google Custom Search Engine.
 */

function google_cse_get_cse_tag() {
  $display = \Drupal::config('search.page.google_cse_search')->get('configuration')['custom_results_display'];

  switch ($display) {
    case 'overlay':
    case 'compact':
    case 'full-width':
      return '<gcse:search gname="google_cse"></gcse:search>';

    case 'two-page':
      return '<gcse:searchbox-only gname="google_cse"></gcse:searchbox-only><gcse:searchresults-only gname="google_cse"></gcse:searchresults-only>';


    case 'two-column':
      return '<gcse:searchbox gname="google_cse"></gcse:searchbox><gcse:searchresults gname="google_cse"></gcse:searchresults>';


    case 'results-only':
      return '<gcse:searchresults-only gname="google_cse"></gcse:searchresults-only>';

    case 'google-hosted':
      return '<gcse:searchbox-only gname="google_cse"></gcse:searchbox-only>';

    default:
      \Drupal::logger('google_cse')->critical('Invalid custom result display %display', array('%display' => $display));
  }
}

/**
 * The search results page can be themed/customized.
 */
function template_preprocess_google_cse_results(&$variables) {
  $form_state = new \Drupal\Core\Form\FormState;
  $variables['results_searchbox_form'] = $variables['form'] ? google_cse_results_searchbox_form($form, $form_state) : '';
  $variables['cse_tag'] = google_cse_get_cse_tag();
  $url = \Drupal\Core\Url::fromUri('http://' . \Drupal::config('search.page.google_cse_search')->get('configuration')['domain'] . '/cse', [
    'query' => $_GET['keys']]);
  $variables['noscript'] = t('!google, or enable JavaScript to view them here.', [
    '!google' => \Drupal\Core\Link::fromTextAndUrl('View the results at Google', $url)
    ]
  );
  $variables['prefix'] = \Drupal\Component\Utility\Xss::filterAdmin(\Drupal::config('search.page.google_cse_search')->get('configuration')['results_prefix']);
  $variables['suffix'] = \Drupal\Component\Utility\Xss::filterAdmin(\Drupal::config('search.page.google_cse_search')->get('configuration')['results_suffix']);

  if (google_cse_validate_request()) {
    // @TODO Implement properly.
    google_cse_library_info_build();
  }
}

/**
 * Validate GET parameters to avoid displaying inappropriate search results.
 */
function google_cse_validate_request() {
  return (
    (empty($_GET['cx']) || $_GET['cx'] == \Drupal::config('search.page.google_cse_search')->get('configuration')['cx']) &&
    (empty($_GET['safe']) || $_GET['safe'] == \Drupal::config('search.page.google_cse_search')->get('configuration')['safe']) &&
    (empty($_GET['sitesearch']) || (($options = \Drupal::service('google_cse.services')->sitesearchOptions()) && isset($options[$_GET['sitesearch']])))
  );
}

/**
 * Form builder for the searchbox forms.
 */
function google_cse_results_searchbox_form($form = array(), &$form_state) {
  if (\Drupal::config('search.page.google_cse_search')->get('configuration')['results_display'] == 'here') {
    $cof = \Drupal::config('search.page.google_cse_search')->get('configuration')['cof_here'];
  }
  else {
    $form['#action'] = 'http://' . \Drupal::config('search.page.google_cse_search')->get('configuration')['domain'] . '/cse';
    $cof = \Drupal::config('search.page.google_cse_search')->get('configuration')['cof_google'];
  }
  $form['#method'] = 'get';
  $form['cx'] = array(
    '#type' => 'hidden',
    '#value' => \Drupal::config('search.page.google_cse_search')->get('configuration')['cx'],
  );
  $form['cof'] = array(
    '#type' => 'hidden',
    '#value' => $cof,
  );
  $form['query'] = array(
    '#type' => 'textfield',
    '#default_value' => isset($_GET['query']) ? $_GET['query'] : '',
  );
  $form['sa'] = array(
    '#type' => 'submit',
    '#value' => t('Search'),
  );
  foreach (\Drupal::service('google_cse.services')->advancedSettings() as $parameter => $setting) {
    $form[$parameter] = array(
      '#type' => 'hidden',
      '#value' => $setting,
    );
  }
  $form['query']['#size'] = intval(\Drupal::config('search.page.google_cse_search')->get('configuration')['results_searchbox_width']);
  $form['query']['#title'] = t('Enter your keywords');

  \Drupal::service('google_cse.services')->siteSearchForm($form);
  return \Drupal::formBuilder()->buildForm($form, $form_state);
}
