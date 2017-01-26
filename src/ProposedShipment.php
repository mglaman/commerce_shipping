<?php

namespace Drupal\commerce_shipping;

/**
 * Represents a proposed shipment.
 *
 * Proposed shipments are returned from the packing process, and then mapped
 * to new or existing shipment entities. This allows the packers to be run
 * whenever the order changes, while only modifying the shipments if they
 * have changed.
 */
class ProposedShipment {

  /**
   * The order ID.
   *
   * @var int
   */
  protected $orderId;

  /**
   * The shipping profile ID.
   *
   * @var int
   */
  protected $shippingProfileId;

  /**
   * The shipment items.
   *
   * @var \Drupal\commerce_shipping\ShipmentItem[]
   */
  protected $items = [];

  /**
   * The package type plugin ID.
   *
   * @var string
   */
  protected $packageTypeId;

  /**
   * The custom fields.
   *
   * @var array
   */
  protected $customFields = [];

  /**
   * Constructs a new ProposedShipment object.
   *
   * @param array $definition
   *   The definition.
   */
  public function __construct(array $definition) {
    foreach (['order_id', 'shipping_profile_id', 'items'] as $required_property) {
      if (empty($definition[$required_property])) {
        throw new \InvalidArgumentException(sprintf('Missing required property "%s".', $required_property));
      }
    }
    foreach ($definition['items'] as $shipment_item) {
      if (!($shipment_item instanceof ShipmentItem)) {
        throw new \InvalidArgumentException('Each shipment item under the "items" property must be an instance of ShipmentItem.');
      }
    }

    $this->orderId = $definition['order_id'];
    $this->shippingProfileId = $definition['shipping_profile_id'];
    $this->items = $definition['items'];
    if (!empty($definition['package_type_id'])) {
      $this->packageTypeId = $definition['package_type_id'];
    }
    if (!empty($definition['custom_fields'])) {
      $this->customFields = $definition['custom_fields'];
    }
  }

  /**
   * Gets the parent order ID.
   *
   * @return int
   *   The order ID.
   */
  public function getOrderId() {
    return $this->orderId;
  }

  /**
   * Gets the shipping profile ID.
   *
   * @return int
   *   The shipping profile ID.
   */
  public function getShippingProfileId() {
    return $this->shippingProfileId;
  }

  /**
   * Gets the shipment items.
   *
   * @return \Drupal\commerce_shipping\ShipmentItem[]
   *   The shipment items.
   */
  public function getItems() {
    return $this->items;
  }

  /**
   * Gets the package type plugin ID.
   *
   * If the proposed shipment returns no package type ID, shipping methods
   * are expected to use their default package type.
   *
   * @return string|null
   *   The package type plugin ID, or NULL if unknown.
   */
  public function getPackageTypeId() {
    return $this->packageTypeId;
  }

  /**
   * Gets the custom fields.
   *
   * @return array
   *   The custom fields, in the $field_name => $value format.
   */
  public function getCustomFields() {
    return $this->customFields;
  }

}