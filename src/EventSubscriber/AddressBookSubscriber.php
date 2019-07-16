<?php

namespace Drupal\commerce_shipping\EventSubscriber;

use Drupal\commerce_order\AddressBookInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderAssignEvent;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Copies the order's shipping information to the customer's address book.
 */
class AddressBookSubscriber implements EventSubscriberInterface {

  /**
   * The address book.
   *
   * @var \Drupal\commerce_order\AddressBookInterface
   */
  protected $addressBook;

  /**
   * Constructs a new AddressBookSubscriber object.
   *
   * @param \Drupal\commerce_order\AddressBookInterface $address_book
   *   The address book.
   */
  public function __construct(AddressBookInterface $address_book) {
    $this->addressBook = $address_book;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      'commerce_order.place.post_transition' => ['onOrderPlace', 100],
      'commerce_order.order.assign' => ['onOrderAssign', 100],
    ];
  }

  /**
   * Copies the order's shipping information when the order is placed.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $profile = $this->getShippingProfile($order);
    $customer = $order->getCustomer();
    if ($profile && $this->addressBook->needsCopy($profile)) {
      $this->addressBook->copy($profile, $customer);
    }
  }

  /**
   * Copies the order's shipping information when the order is assigned.
   *
   * @param \Drupal\commerce_order\Event\OrderAssignEvent $event
   *   The event.
   */
  public function onOrderAssign(OrderAssignEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getOrder();
    $profile = $this->getShippingProfile($order);
    $customer = $event->getCustomer();
    if ($profile && $this->addressBook->needsCopy($profile)) {
      $this->addressBook->copy($profile, $customer);
    }
  }

  /**
   * Gets the shipping profile for the given order.
   *
   * The shipping profile is assumed to be the same for all shipments.
   * Therefore, it is taken from the first found shipment.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\profile\Entity\ProfileInterface|null
   *   The shipping profile, or NULL if none found.
   */
  protected function getShippingProfile(OrderInterface $order) {
    if (!$order->hasField('shipments')) {
      // The order is not shippable.
      return NULL;
    }
    $shipping_profile = NULL;
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    foreach ($order->get('shipments')->referencedEntities() as $shipment) {
      $shipping_profile = $shipment->getShippingProfile();
      break;
    }

    return $shipping_profile;
  }

}
