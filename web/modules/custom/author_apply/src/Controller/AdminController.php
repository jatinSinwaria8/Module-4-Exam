<?php

declare(strict_types=1);

namespace Drupal\author_apply\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for administration pages for author applications.
 */
final class AdminController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * AdminController constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    Connection $database,
    MailManagerInterface $mail_manager,
    DateFormatterInterface $date_formatter,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->database = $database;
    $this->mailManager = $mail_manager;
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): AdminController {
    return new static(
      $container->get('database'),
      $container->get('plugin.manager.mail'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Builds a table of pending author applications.
   *
   * Users with the 'blogger' or 'guest_blogger' role and status = 0 are
   * considered pending.
   *
   * @return array
   *   A render array for the admin table.
   */
  public function listApplications(): array {
    $header = [
      $this->t('UID'),
      $this->t('Name'),
      $this->t('Email'),
      $this->t('Role'),
      $this->t('Created'),
      $this->t('Email verified'),
      $this->t('Operations'),
    ];

    $storage = $this->entityTypeManager->getStorage('user');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 0)
      ->condition('roles', ['blogger', 'guest_blogger'], 'IN');

    $uids = $query->execute();

    $rows = [];
    if (!empty($uids)) {
      /** @var \Drupal\user\UserInterface[] $users */
      $users = $storage->loadMultiple($uids);

      foreach ($users as $user) {
        $record = $this->database->select('author_apply_otp', 'a')
          ->fields('a', ['verified'])
          ->condition('uid', $user->id())
          ->range(0, 1)
          ->orderBy('id', 'DESC')
          ->execute()
          ->fetchAssoc();

        $verified = ($record && (int) ($record['verified'] ?? 0) === 1) ? $this->t('Yes') : $this->t('No');

        $approve_url = Url::fromRoute('author_apply.approve', ['uid' => $user->id()]);
        $approve_link = Link::fromTextAndUrl($this->t('Approve'), $approve_url)->toRenderable();

        $rows[] = [
          $user->id(),
          $user->getDisplayName(),
          $user->getEmail(),
          implode(', ', $user->getRoles()),
          $this->dateFormatter->format($user->getCreatedTime(), 'short'),
          $verified,
          $approve_link,
        ];
      }
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No pending applications.'),
    ];
  }

  /**
   * Approves a user application: unblocks the user and sends a notification email.
   *
   * @param int $uid
   *   The user ID to approve.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the admin applications list.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when the current user lacks the required permission.
   */
  public function approve(int $uid): RedirectResponse {
    if (!$this->currentUser()->hasPermission('approve author applications')) {
      throw new AccessDeniedHttpException();
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof User) {
      $this->messenger()->addError($this->t('User not found.'));
      return $this->redirect('author_apply.admin_list');
    }

    $user->set('status', 1);
    $user->save();

    $params = ['full_name' => $user->getDisplayName()];
    $this->mailManager->mail(
      'author_apply',
      'approved',
      $user->getEmail(),
      $user->getPreferredLangcode(),
      $params,
      NULL,
      TRUE
    );

    $this->messenger()->addStatus($this->t('@name approved.', ['@name' => $user->getAccountName()]));
    return $this->redirect('author_apply.admin_list');
  }

}
