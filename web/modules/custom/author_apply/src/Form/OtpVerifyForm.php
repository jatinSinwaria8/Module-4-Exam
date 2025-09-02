<?php

declare(strict_types=1);

namespace Drupal\author_apply\Form;

use Drupal\user\UserInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for verifying author OTP tokens.
 */
class OtpVerifyForm extends FormBase implements ContainerInjectionInterface {
  use MessengerTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    TimeInterface $time,
    RouteMatchInterface $routeMatch,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->time = $time;
    $this->routeMatch = $routeMatch;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return string
   *   The form ID.
   */
  public function getFormId() {
    return 'author_apply_verify_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $uid = $this->routeMatch->getParameter('uid');
    if ($uid instanceof UserInterface) {
      $uid = $uid->id();
    }

    $token = $this->routeMatch->getParameter('token');

    $form['uid'] = [
      '#type' => 'hidden',
      '#value' => $uid,
    ];

    $form['token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Verification token'),
      '#default_value' => $token ?? '',
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'verify' => [
        '#type' => 'submit',
        '#value' => $this->t('Verify email'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = (int) $form_state->getValue('uid');
    $tokenPlain = $form_state->getValue('token');
    $tokenHash = hash('sha256', (string) $tokenPlain);
    $now = $this->time->getRequestTime();

    $record = $this->database->select('author_apply_otp', 'a')
      ->fields('a', ['id', 'expires', 'verified'])
      ->condition('uid', $uid)
      ->condition('token_hash', $tokenHash)
      ->execute()
      ->fetchAssoc();

    if (empty($record)) {
      $this->messenger()->addError($this->t('Invalid token.'));
      return;
    }

    if ((int) $record['verified'] === 1) {
      $this->messenger()->addStatus($this->t('This token has already been used.'));
      return;
    }

    if ((int) $record['expires'] < $now) {
      $this->messenger()->addError($this->t('This token has expired.'));
      return;
    }

    $this->database->update('author_apply_otp')
      ->fields(['verified' => 1])
      ->condition('id', $record['id'])
      ->execute();

    $this->messenger()->addStatus($this->t('Your email has been verified. An admin will review your application.'));
    $form_state->setRedirect('<front>');
  }

}
