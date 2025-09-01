<?php

declare(strict_types=1);

namespace Drupal\author_apply\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a registration form for users applying to be authors.
 */
class AuthorRegisterForm extends FormBase implements ContainerInjectionInterface
{
  use MessengerTrait;

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger factory service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The time service.
   *
   * @var \Drupal\Core\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new AuthorRegisterForm.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    MailManagerInterface $mail_manager,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    AccountProxyInterface $current_user,
    TimeInterface $time,
    ConfigFactoryInterface $config_factory
  ) {
    $this->mailManager = $mail_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->loggerFactory = $logger_factory;
    $this->currentUser = $current_user;
    $this->time = $time;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('logger.factory'),
      $container->get('current_user'),
      $container->get('datetime.time'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   */
  public function getFormId()
  {
    return 'author_apply_register_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['full_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full name'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
    ];

    $form['pass'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#required' => TRUE,
    ];

    $form['author_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Author type'),
      '#options' => [
        'blogger' => $this->t('Blogger'),
        'guest_blogger' => $this->t('Guest Blogger'),
      ],
      '#default_value' => 'guest_blogger',
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Apply'),
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
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    $mail = $form_state->getValue('mail');

    $existing = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['mail' => $mail]);

    if (!empty($existing)) {
      $form_state->setErrorByName('mail', $this->t('This email is already registered.'));
    }

    $pass = $form_state->getValue('pass');

    if (strlen((string) $pass) < 6) {
      $form_state->setErrorByName('pass', $this->t('Password must be at least 6 characters.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $fullName = $form_state->getValue('full_name');
    $mail = $form_state->getValue('mail');
    $password = $form_state->getValue('pass');
    $type = $form_state->getValue('author_type');

    $username = $this->generateUniqueUsername((string) $fullName, (string) $mail);

    $userStorage = $this->entityTypeManager->getStorage('user');

    $user = $userStorage->create([
      'name' => $username,
      'mail' => $mail,
      'pass' => $password,
      'status' => 0,
      'init' => $mail,
    ]);

    $user->addRole($type);
    $user->save();

    $tokenPlain = bin2hex(random_bytes(16));
    $tokenHash = hash('sha256', $tokenPlain);
    $requestTime = $this->time->getRequestTime();
    $expires = $requestTime + 86400;

    $this->database->insert('author_apply_otp')
      ->fields([
        'uid' => $user->id(),
        'token_hash' => $tokenHash,
        'expires' => $expires,
        'verified' => 0,
        'created' => $requestTime,
      ])
      ->execute();

    $verifyUrl = Url::fromRoute('author_apply.verify', ['uid' => $user->id(), 'token' => $tokenPlain], ['absolute' => TRUE])->toString();

    $mailParams = [
      'verify_url' => $verifyUrl,
      'token_plain' => $tokenPlain,
    ];

    $langcode = $this->currentUser->getPreferredLangcode();

    $this->mailManager->mail('author_apply', 'otp', $mail, $langcode, $mailParams, NULL, TRUE);

    $siteMail = $this->configFactory->get('system.site')->get('mail');

    $adminLink = Url::fromRoute('author_apply.admin_list', [], ['absolute' => TRUE])->toString();

    $adminParams = [
      'full_name' => $fullName,
      'mail' => $mail,
      'type' => $type,
      'admin_link' => $adminLink,
    ];

    if (!empty($siteMail)) {
      $this->mailManager->mail('author_apply', 'notify_admin', $siteMail, $langcode, $adminParams, NULL, TRUE);
    }

    $thankParams = ['full_name' => $fullName];

    $this->mailManager->mail('author_apply', 'thank_you', $mail, $langcode, $thankParams, NULL, TRUE);

    $this->messenger()->addStatus($this->t('Thanks for applying. A verification email has been sent to your address.'));

    $form_state->setRedirect('<front>');
  }

  /**
   * Generates a unique username from a full name or email address.
   *
   * @param string $fullName
   *   The full name of the user.
   * @param string $mail
   *   The email address of the user.
   *
   * @return string
   *   A unique username.
   */
  protected function generateUniqueUsername(string $fullName, string $mail): string
  {
    $base = strtolower(trim($fullName));
    $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?: '';
    $base = trim($base, '_');

    if ($base === '') {
      $local = strstr($mail, '@', TRUE) ?: 'user';
      $base = preg_replace('/[^a-z0-9]+/', '_', strtolower($local)) ?: 'user';
      $base = trim($base, '_');
    }

    $base = substr($base, 0, 48) ?: 'user';

    $storage = $this->entityTypeManager->getStorage('user');
    $candidate = $base;
    $i = 1;

    while ($storage->loadByProperties(['name' => $candidate])) {
      $candidate = $base . '_' . $i;
      $i++;
    }

    return $candidate;
  }

}
