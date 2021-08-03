<?php

namespace Drupal\tamper_string_replace_multiple\Plugin\Tamper;

use Drupal\Core\Form\FormStateInterface;
use Drupal\tamper\TamperableItemInterface;
use Drupal\tamper\TamperBase;

/**
 * Plugin implementation for rewriting a value.
 *
 * @Tamper(
 *   id = "string_replace_multiple",
 *   label = @Translation("String Replace (Multiple)"),
 *   description = @Translation("Replace more than one word/phrase at a time."),
 *   category = "Other"
 * )
 */
class StringReplaceMultiple extends TamperBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    $config['allowed_values'] = [];
    $config['trim_right'] = NULL;
    $config['trim_left'] = NULL;

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $allowed_values = $this->getSetting('allowed_values');
    $allowed_values_function = $this->getSetting('allowed_values_function');

    /**
     * 'allowed_values' is the form item name in ListItemBase.php, from which I
     * took most of this code. I did not bother changing it, but that might be
     * worth considering if this plugin is incorporated into Tamper.
     */
    $form['allowed_values'] = [
      '#type' => 'textarea',
      '#title' => t('Replacement pairs'),
      '#default_value' => $this->allowedValuesString($allowed_values),
      '#rows' => 10,
      '#access' => empty($allowed_values_function),
      '#element_validate' => [[get_class($this), 'validateAllowedValues']],
      '#field_has_data' => $has_data ?? FALSE,
      '#allowed_values' => $allowed_values,
      '#description' => $this->t('Add the terms you would like to replace and what you would like to replace them with, one per line, using this syntax: Source String|Replacement String.'),
    ];

    $form['allowed_values_function'] = [
      '#type' => 'item',
      '#title' => t('Allowed values list'),
      '#markup' => t('The value of this field is being determined by the %function function and may not be changed.', ['%function' => $allowed_values_function]),
      '#access' => !empty($allowed_values_function),
      '#value' => $allowed_values_function,
    ];

    $form['trim_right'] = [
      '#type' => 'textfield',
      '#attributes' => [
        'type' => 'number',
      ],
      '#title' => $this->t('Characters to remove from the right'),
      '#default_value' => $this->getSetting('trim_right'),
      '#description' => $this->t('You can use this to remove characters from the right side of the source in order for it to match the word or phrase to be replaced. For example, if you had a source like "ReplaceMe 2021" and you wanted to only replace "ReplaceMe", you could trim 5 characters from the end of the string, which would include the space and the year. Please enter POSITIVE integers only.'),
    ];

    $form['trim_left'] = [
      '#type' => 'textfield',
      '#attributes' => [
        'type' => 'number',
      ],
      '#title' => $this->t('Characters to remove from the left'),
      '#default_value' => $this->getSetting('trim_left'),
      '#description' => $this->t('You can use this to remove characters from the left side of the source in order for it to match the word or phrase to be replaced. For example, if you had a source like "2021 ReplaceMe" and you wanted to only replace "ReplaceMe", you could trim 5 characters from the beginning of the string, which would include the space and the year.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function tamper($data, TamperableItemInterface $item = NULL) {
    if (is_null($item)) {
      // Nothing to replace.
      return $data;
    }
    $original_data = $data;
    // This is an array generated from the "Allowed values list" textarea.
    $allowed_values = $this->getSetting('allowed_values');
    // This is an array of the values to be replaced.
    $keys = array_keys($allowed_values);
    // Get values from the "Trim right" and "Trim left" textfields.
    $trim_right = $this->getSetting('trim_right');
    $trim_left = $this->getSetting('trim_left');
    // Perform the trim if a right trim value is set.
    if ($trim_right) {
      $data = substr($data, 0, '-' . $trim_right);
    }
    // Perform the trim if a left trim value is set.
    if ($trim_left) {
      $data = substr($data, 0, $trim_left);
    }
    // If the data is found in the array to be replaced, perform the operation.
    if (in_array($data, $keys)) {

      return str_replace($data, $allowed_values[$data], $original_data);
    }

    return $original_data;
  }

  /**
   * #element_validate callback for options field allowed values.
   *
   * @param $element
   *   An associative array containing the properties and children of the
   *   generic form element.
   * @param $form_state
   *   The current state of the form for the form this element belongs to.
   *
   * @see \Drupal\Core\Render\Element\FormElement::processPattern()
   */
  public static function validateAllowedValues($element, FormStateInterface $form_state) {
    $values = static::extractAllowedValues($element['#value'], $element['#field_has_data']);

    if (!is_array($values)) {
      $form_state->setError($element, t('Allowed values list: invalid input.'));
    }
    else {
      // Check that keys are valid for the field type.
      foreach ($values as $key => $value) {
        if ($error = static::validateAllowedValue($key)) {
          $form_state->setError($element, $error);
          break;
        }
      }

      // Prevent removing values currently in use.
      if ($element['#field_has_data']) {
        $lost_keys = array_keys(array_diff_key($element['#allowed_values'], $values));
        if (_options_values_in_use($element['#entity_type'], $element['#field_name'], $lost_keys)) {
          $form_state->setError($element, t('Allowed values list: some values are being removed while currently in use.'));
        }
      }

      $form_state->setValueForElement($element, $values);
    }
  }

  /**
   * Extracts the allowed values array from the allowed_values element.
   *
   * @param string $string
   *   The raw string to extract values from.
   * @param bool $has_data
   *   The current field already has data inserted or not.
   *
   * @return array|null
   *   The array of extracted key/value pairs, or NULL if the string is invalid.
   *
   * @see \Drupal\options\Plugin\Field\FieldType\ListItemBase::allowedValuesString()
   */
  protected static function extractAllowedValues($string, $has_data) {
    $values = [];

    $list = explode("\n", $string);
    $list = array_map('trim', $list);
    $list = array_filter($list, 'strlen');

    $generated_keys = $explicit_keys = FALSE;
    foreach ($list as $position => $text) {
      // Check for an explicit key.
      $matches = [];
      if (preg_match('/(.*)\|(.*)/', $text, $matches)) {
        // Trim key and value to avoid unwanted spaces issues.
        $key = trim($matches[1]);
        $value = trim($matches[2]);
        $explicit_keys = TRUE;
      }
      // Otherwise see if we can use the value as the key.
      elseif (!static::validateAllowedValue($text)) {
        $key = $value = $text;
        $explicit_keys = TRUE;
      }
      // Otherwise see if we can generate a key from the position.
      elseif (!$has_data) {
        $key = (string) $position;
        $value = $text;
        $generated_keys = TRUE;
      }
      else {
        return;
      }

      $values[$key] = $value;
    }

    // We generate keys only if the list contains no explicit key at all.
    if ($explicit_keys && $generated_keys) {
      return;
    }

    return $values;
  }

  /**
   * Checks whether a candidate allowed value is valid.
   *
   * @param string $option
   *   The option value entered by the user.
   *
   * @return string
   *   The error message if the specified value is invalid, NULL otherwise.
   */
  protected static function validateAllowedValue($option) {}

  /**
   * Generates a string representation of an array of 'allowed values'.
   *
   * This string format is suitable for edition in a textarea.
   *
   * @param array $values
   *   An array of values, where array keys are values and array values are
   *   labels.
   *
   * @return string
   *   The string representation of the $values array:
   *    - Values are separated by a carriage return.
   *    - Each value is in the format "value|label" or "value".
   */
  protected function allowedValuesString($values) {
    $lines = [];
    foreach ($values as $key => $value) {
      $lines[] = "$key|$value";
    }
    return implode("\n", $lines);
  }

}
