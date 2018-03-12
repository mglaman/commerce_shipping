<?php

namespace Drupal\commerce_shipping\Element;

use Drupal\commerce\Element\CommerceElementTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Render\Element;


/**
 * Provides a form element for embedding the shipment form.
 *
 * Usage example:
 * @code
 * $form['shipment_form'] = [
 *   '#type' => 'commerce_shipment_form',
 *   '#order' => $order,
 *   '#force_packing' => TRUE,
 * ];
 * @endcode
 *
 * @FormElement("commerce_shipment_form")
 */
class ShippingForm extends FormElement {

  use CommerceElementTrait;

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_called_class();
    return [
      '#order' => '',
      '#force_packing' => TRUE,
      '#input' => TRUE,

      '#process' => [
        [$class, 'processForm'],
      ],
      '#element_validate' => [
        [$class, 'validateForm'],
      ],
      '#commerce_element_submit' => [
        [$class, 'submitForm'],
      ],
    ];
  }

  /**
   * Builds the shipment form.
   *
   * @param array $element
   *   The form element being processed.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form structure.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the #order property is empty.
   *
   * @return array
   *   The processed form element.
   */
  public static function processForm(array $element, FormStateInterface $form_state, array &$complete_form) {
    if (empty($element['#order'])) {
      throw new \InvalidArgumentException('The commerce_shipping_form element requires the #order property.');
    }

    $store = $element['#order']->getStore();
    $shipping_profile = $form_state->get('shipping_profile');
    if (!$shipping_profile) {
      $shipping_profile = static::getShippingProfile($element['#order']);
      $form_state->set('shipping_profile', $shipping_profile);
    }
    $available_countries = [];
    foreach ($store->get('shipping_countries') as $country_item) {
      $available_countries[] = $country_item->value;
    }

    // Prepare the form for ajax.
    // Not using Html::getUniqueId() on the wrapper ID to avoid #2675688.
    $element['#wrapper_id'] = 'shipping-information-wrapper';
    $element['#prefix'] = '<div id="' . $element['#wrapper_id'] . '">';
    $element['#suffix'] = '</div>';

    $element['shipping_profile'] = [
      '#type' => 'commerce_profile_select',
      '#default_value' => $shipping_profile,
      '#default_country' => $store->getAddress()->getCountryCode(),
      '#available_countries' => $available_countries,
    ];
    $element['recalculate_shipping'] = [
      '#type' => 'button',
      '#value' => t('Recalculate shipping'),
      '#recalculate' => TRUE,
      '#ajax' => [
        'callback' => [get_called_class(), 'ajaxRefresh'],
        'wrapper' => $element['#wrapper_id'],
      ],
      // The calculation process only needs a valid shipping profile.
      '#limit_validation_errors' => [
        array_merge($element['#parents'], ['shipping_profile']),
      ],
    ];
    $element['removed_shipments'] = [
      '#type' => 'value',
      '#value' => [],
    ];
    $element['shipments'] = [
      '#type' => 'container',
    ];

    $shipments = $element['#order']->shipments->referencedEntities();
    $recalculate_shipping = $form_state->get('recalculate_shipping');
    if ($recalculate_shipping || $element['#force_packing']) {
      list($shipments, $removed_shipments) = \Drupal::service('commerce_shipping.packer_manager')->packToShipments($element['#order'], $shipping_profile, $shipments);

      // Store the IDs of removed shipments for submitPaneForm().
      $element['removed_shipments']['#value'] = array_map(function ($shipment) {
        /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
        return $shipment->id();
      }, $removed_shipments);
    }

    $single_shipment = count($shipments) === 1;
    foreach ($shipments as $index => $shipment) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
      $element['shipments'][$index] = [
        '#parents' => array_merge($element['#parents'], ['shipments', $index]),
        '#array_parents' => array_merge($element['#parents'], ['shipments', $index]),
        '#type' => $single_shipment ? 'container' : 'fieldset',
        '#title' => $shipment->getTitle(),
      ];
      $form_display = EntityFormDisplay::collectRenderDisplay($shipment, 'default');
      $form_display->removeComponent('shipping_profile');
      $form_display->removeComponent('title');
      $form_display->buildForm($shipment, $element['shipments'][$index], $form_state);
      $element['shipments'][$index]['#shipment'] = $shipment;
    }
    return $element;
  }

  /**
   * Ajax callback.
   */
  public static function ajaxRefresh(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $parents = array_slice($triggering_element['#parents'], 0, -1);
    return NestedArray::getValue($form, $parents);
  }

  /**
   * Validates the shipment form.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public static function validateForm(array &$element, FormStateInterface $form_state) {
    $shipment_indexes = Element::children($element['shipments']);
    $triggering_element = $form_state->getTriggeringElement();
    $recalculate = !empty($triggering_element['#recalculate']);
    $button_type = isset($triggering_element['#button_type']) ? $triggering_element['#button_type'] : '';
    if (!$recalculate && $button_type == 'primary' && empty($shipment_indexes)) {
      // The checkout step was submitted without shipping being calculated.
      // Force the recalculation now and reload the page.
      $recalculate = TRUE;
      drupal_set_message(t('Please select a shipping method.'), 'error');
      $form_state->setRebuild(TRUE);
    }

    if ($recalculate) {
      $form_state->set('recalculate_shipping', TRUE);
      // The profile in form state needs to reflect the submitted values, since
      // it will be passed to the packers when the form is rebuilt.
      $form_state->set('shipping_profile', $element['shipping_profile']['#profile']);
    }

    foreach ($shipment_indexes as $index) {
      $shipment = clone $element['shipments'][$index]['#shipment'];
      $form_display = EntityFormDisplay::collectRenderDisplay($shipment, 'default');
      $form_display->removeComponent('shipping_profile');
      $form_display->removeComponent('title');
      $form_display->extractFormValues($shipment, $element['shipments'][$index], $form_state);
      $form_display->validateFormValues($shipment, $element['shipments'][$index], $form_state);
    }
  }

  /**
   * Submits the shipment form.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public static function submitForm(array &$element, FormStateInterface $form_state) {
    // Save the modified shipments.
    $shipments = [];
    foreach (Element::children($element['shipments']) as $index) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
      $shipment = clone $element['shipments'][$index]['#shipment'];
      $form_display = EntityFormDisplay::collectRenderDisplay($shipment, 'default');
      $form_display->removeComponent('shipping_profile');
      $form_display->removeComponent('title');
      $form_display->extractFormValues($shipment, $element['shipments'][$index], $form_state);
      $shipment->setShippingProfile($element['shipping_profile']['#profile']);
      $shipment->save();
      $shipments[] = $shipment;
    }
    $element['#order']->shipments = $shipments;
    $element['#order']->save();

    // Delete shipments that are no longer in use.
    $removed_shipment_ids = $element['removed_shipments']['#value'];
    if (!empty($removed_shipment_ids)) {
      $shipment_storage = \Drupal::service('entity_type.manager')->getStorage('commerce_shipment');
      $removed_shipments = $shipment_storage->loadMultiple($removed_shipment_ids);
      $shipment_storage->delete($removed_shipments);
    }
  }

  /**
   * Gets the shipping profile.
   *
   * The shipping profile is assumed to be the same for all shipments.
   * Therefore, it is taken from the first found shipment, or created from
   * scratch if no shipments were found.
   *
   * @return \Drupal\profile\Entity\ProfileInterface
   *   The shipping profile.
   */
  public static function getShippingProfile($order) {
    $shipping_profile = NULL;
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    foreach ($order->shipments->referencedEntities() as $shipment) {
      $shipping_profile = $shipment->getShippingProfile();
      break;
    }
    if (!$shipping_profile) {
      $shipping_profile = \Drupal::service('entity_type.manager')->getStorage('profile')->create([
        'type' => 'customer',
        'uid' => $order->getCustomerId(),
      ]);
    }

    return $shipping_profile;
  }

}
