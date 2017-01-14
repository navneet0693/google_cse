<?php

/**
 * @file
 * Services to use Google Custom Search Engine (CSE) without frames and ads.
 */

namespace Drupal\google_cse;

use Drupal\Core\Url;
use Drupal\Component\Utility\Html;

class GoogleCSEAdv {

  /**
   * Maximum number of results from a Google search.
   */
  const GOOGLE_MAX_SEARCH_RESULTS = 1000;

  /**
   * Sends query to Google's Custom Search Engine and returns response.
   *
   * @param string $keys
   *   The search terms.
   *
   * @param int $offset
   *   The result number to start at.
   *
   * @return string
   *   XML response string.
   */
  public function GoogleCSEAdvService($keys, $offset = 0) {
    $page = 0;
    $response = array();

    if (isset($_GET['page'])) {
      $page = $_GET['page'];
    }
    if (isset($response[$keys])) {
      return $response[$keys];
    }

    // Number of results per page. 10 is the default for Google CSE.
    $rows = (int) \Drupal::config('googlcse.settings')->get('google_cse_adv_results_per_page');

    $query = array(
      'cx' => \Drupal::config('googlcse.settings')->get('google_cse_cx'),
      'client' => 'google-csbe',
      'output' => 'xml_no_dtd',
      'filter' => '1',
      'hl' => $this->GoogleCSEAdvParamhl(),
      'lr' => $this->GoogleCSEAdvParamlr(),
      'q' => $keys,
      'num' => $rows,
      'start' => ($offset) ? $offset : ($page * $rows),
      'as_sitesearch' => \Drupal::config('googlcse.settings')->get('google_cse_limit_domain'),
    );

    if (isset($_GET['more'])) {
      $query['+more:'] = urlencode($_GET['more']);
    }

    $url = Url::fromUri('http://www.google.com/cse',['query' => $query]);

    // Get the google response.
    $response = $this->GoogleCSEAdvGetResponce($url);

    return $response;
  }

  /**
   * Returns "hl" language param for search request.
   *
   * @return string
   *   The language code.
   */
  public function GoogleCSEAdvParamhl() {

    $language = \Drupal::config('googlcse.settings')->get('google_cse_adv_language');
    switch ($language) {
      case 'active':
        global $language;
        return $language->language;

      default:
        return '';
    }
  }

  /**
   * Returns "lr" language param for search request.
   *
   * @return string
   */
  public function GoogleCSEAdvParamlr() {
    switch (\Drupal::config('googlcse.settings')->get('google_cse_adv_language')) {
      case 'active':
        global $language;
        return 'lang_' . $language->language;

      default:
        return '';
    }
  }

  /**
   * Given the url with the search we try to do, get response from Google.
   *
   * @param string $url
   *   The Google URL to query.
   *
   * @return string
   *   The response from Google.
   */
  public function GoogleCSEAdvGetResponce($url) {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    // Return into a variable.
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);

    if (\Drupal::moduleHandler()->moduleExists('proxy_settings') && $proxy_host = proxy_settings_host('google_cse_adv')) {

      if ($proxy_port = proxy_settings_port('google_cse_adv')) {
        curl_setopt($curl, CURLOPT_PROXY, $proxy_host . ':' . $proxy_port);
      }
      else {
        curl_setopt($curl, CURLOPT_PROXY, $proxy_host);
      }
      if ($user = proxy_settings_username('google_cse_adv') && $password = proxy_settings_password('google_cse_adv')) {
        curl_setopt($curl, CURLOPT_PROXYUSERPWD, $user . ':' . $password);
      }
    }

    $response[] = curl_exec($curl);
    curl_close($curl);

    return $response;
  }

  /**
   * Returns the thumbnail properly themed if configured to do so.
   *
   * @param array $img
   *   Image array.
   *
   * @return string
   *   The HTML for the image.
   */
  protected function GoogleCSEAdvThumbnail($title, $image_att) {
    if (\Drupal::config('googlcse.settings')->get('google_cse_results_display_images')) {
      // @TODO Check this after the module development
      $image = [
        'type' => 'image',
        'path' => isset($image_att['value']) ? $image_att['value'] : '',
        'alt' => $title,
        'title' => $title,
        'attributes' => array('width' => '100px'),
        'getsize' => FALSE
      ];
      return \Drupal::service('renderer')->render($image);
    }
    return '';
  }

  /**
   * Function to fetch the results xml from Google.
   *
   * @param string $response
   * @param string $keys
   * @param string $conditions
   *
   * @return string
   */
  public function GoogleCSEAdvResponseResults($response, $keys, $conditions) {
    $xml = simplexml_load_string($response);
    $results = array();
    // Number of results.
    $total = 0;

    if (isset($xml->RES->R)) {

      // Cap the result total if necessary.
      // Google will not return more than 1000 results, but RES->M may
      // be higher than this, which messes up our paging. Retain a copy
      // of the original so that themers can still display it.
      // Also, any result beyond pages 8x and 99 tends to repeat themselves, so
      // they are not relevant. Limited then to 150 pages (1500)
      $max_results = \Drupal::config('googlcse.settings')->get('google_cse_adv_maximum_results');

      $total = (int) $xml->RES->M;
      $xml->RES->M_ORIGINAL = $total;

      // Is the result accurate?
      if (!$this->GoogleCSEAdvResultIsAccurate($response)) {
        $total = $this->GoogleCSEAdvGetAccurateNumofResults($keys, $total);
      }

      if ($total > $max_results) {
        $xml->RES->M = $total = $max_results;
      }

      foreach ($xml->RES->R as $result) {

        // Clean the text and remove tags.
        $title = $this->GoogleCSEAdvClearStr((string) $result->T);

        if ($result->PageMap) {
          $att = $result->PageMap->DataObject->attributes();
          switch ($att['type']) {
            case "cse_image":
              $image_att = $result->PageMap->DataObject->Attribute->attributes();

              // Clean the text.
              $text_snippet = $this->GoogleCSEAdvClearStr((string) $result->S);

              // Add a search result image.
              $snippet = $this->GoogleCSEAdvThumbnail($title, $image_att) . $text_snippet;

              // Clean the text.
              $extra = $this->GoogleCSEAdvClearStr((string) $result->U);
              $extra = parse_url($extra);
              $extra = $extra['host'];
              break;

            case "metatags":
              // Clean the string.
              $snippet = $this->GoogleCSEAdvClearStr((string) $result->S);

              // Clean the string.
              $extra = $this->GoogleCSEAdvClearStr(Html::escape((string) $result->U));

              $extra = parse_url($extra);
              $extra = $extra['host'] . " | Document";
              break;
          }
        }
        else {
          if ($result->SL_RESULTS) {
            $snippet = strip_tags((string) $result->SL_RESULTS->SL_MAIN->BODY_LINE->BLOCK->T);
          }
          else {
            $snippet = (string) $result->S;
          }
          // Clean the text.
          $snippet = $this->GoogleCSEAdvClearStr($snippet);

          // Clean the text.
          $extra = $this->GoogleCSEAdvClearStr(Html::escape((string) $result->U));

          $extra = parse_url($extra);
          $extra = $extra['host'];
        }

        // Results in a Drupal themed way for search.
        $results[] = array(
          'link' => (string) $result->U,
          'title' => $title,
          'snippet' => $snippet,
          'keys' => Html::escape($keys),
          'extra' => array($extra),
          'date' => NULL,
        );
      }

      // No pager query was executed - we have to set the pager manually.
      $limit = \Drupal::config('googlcse.settings')->get('google_cse_adv_results_per_page');
      pager_default_initialize($total, $limit);

    }

    // Allow other modules to alter the number of results.
    \Drupal::moduleHandler()->alter('google_cse_num_results', $total);

    return $results;
  }

  /**
   * Check Return if the response from Google is accurate.
   *
   * Google initially estimates the exact number of results
   * that the search should have.
   *
   * @param string $response
   *   The XML response from Google.
   *
   * @return bool
   *   TRUE if the results are considered accurate.
   */
  public function GoogleCSEAdvResultIsAccurate($response) {
    $accurate = FALSE;
    // Time to get the response.
    $xml = simplexml_load_string($response);

    // And to check the "accurate" Google variable, if the XT flag exists
    // the search is accurate.
    if (isset($xml->RES->XT)) {
      $accurate = TRUE;
    }

    return $accurate;
  }


  /**
   * Get the exact (accurate) number of search results to be used in the pager.
   *
   * Google will never return more than 1000 results for any given search. If a
   * request for the maximum results is made, Google will return the last page of
   * the search results with the start and end position as attributes of the results.
   *
   * The <RES> tag encapsulates the set of individual search results and details
   * about those results. The tag attributes are SN (the 1-based index of the first
   * search result returned in this result set) and EN (the 1-based index of the
   * last search result).
   *
   * @param string $keys
   *   The search keys.
   *
   * @param $total
   *   The initial estimated total.
   *
   * @return int
   *   The accurate total number of results.
   */
  public function GoogleCSEAdvGetAccurateNumofResults($keys, $total) {
    $total_num_results = 0;
    // Allow other modules to alter the keys.
    \Drupal::moduleHandler()->alter('google_cse_searched_keys', $keys);
    $offset = self::GOOGLE_MAX_SEARCH_RESULTS - \Drupal::config('googlcse.settings')->get('google_cse_adv_results_per_page');
    $response = $this->GoogleCSEAdvService($keys, $offset);
    $xml = simplexml_load_string($response[0]);
    if (isset($xml->RES)) {
      // Get the 1-based index of the last search result item from the result end
      // attribute (EN) of the search result tag (RES).
      $attributes = $xml->RES->attributes();
      $total_num_results += (int) $attributes['EN'];
    }

    // If we do not find an accurate result we will use the initial estimate.
    if (!$total_num_results) {
      $total_num_results = $total;
    }
    return $total_num_results;
  }


  /**
   * Clean string of html, tags, etc...
   *
   * @param string $input_str
   *   The original string.
   *
   * @return string
   *   The cleaned output.
   */
  public function GoogleCSEAdvClearStr($input_str) {
    $cleaned_str = $input_str;

    if (function_exists('htmlspecialchars_decode')) {
      $cleaned_str = htmlspecialchars_decode($input_str, ENT_QUOTES);
    }

    // Remove possible tags.
    $cleaned_str = strip_tags($cleaned_str);

    return $cleaned_str;
  }

}
