<?php

declare(strict_types=1);

namespace Drupal\blogs_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Controller that exposes the Blogs JSON API.
 */
final class BlogsApiController extends ControllerBase {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   *
   * The entity type manager service.
   */
  protected $entityTypeManager;

  /**
   * Constructs the BlogsApiController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Returns a JSON response with a filtered list of blogs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function list(Request $request): JsonResponse {
    $authors = $this->parseIdList($request->query->get('authors', ''));
    $tags = $this->parseIdList($request->query->get('tags', ''));
    $limit = (int) $request->query->get('limit', 0);
    $start = $this->parseDate($request->query->get('start'));
    $end = $this->parseDate($request->query->get('end'));

    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', 'blogs')
      ->condition('status', 1)
      ->accessCheck(TRUE);

    if ($authors) {
      $query->condition('uid', $authors, 'IN');
    }
    if ($tags) {
      $query->condition('field_blog_content_tag.target_id', $tags, 'IN');
    }
    if ($start) {
      $query->condition('field_published_date.value', $start->format(DATE_ATOM), '>=');
    }
    if ($end) {
      $query->condition('field_published_date.value', $end->format(DATE_ATOM), '<=');
    }

    // Prefer published date if it exists.
    if ($this->entityTypeManager->getStorage('field_config')->load('node.blogs.field_published_date')) {
      $query->sort('field_published_date.value', 'DESC');
    }
    else {
      $query->sort('created', 'DESC');
    }

    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $nids = $query->execute();
    $items = [];

    if ($nids) {
      /** @var \Drupal\node\NodeInterface[] $nodes */
      $nodes = $node_storage->loadMultiple($nids);

      foreach ($nodes as $node) {
        $items[] = $this->normalizeNode($node);
      }
    }

    return new JsonResponse([
      'count' => count($items),
      'data' => $items,
    ]);
  }

  /**
   * Parses a comma-separated list of IDs into integers.
   *
   * @param string $raw
   *   The raw string from query param.
   *
   * @return int[]
   *   List of integer IDs.
   */
  private function parseIdList(string $raw): array {
    return array_filter(array_map('intval', explode(',', $raw)));
  }

  /**
   * Parses a date string into DateTimeImmutable.
   *
   * @param string|null $raw
   *   The raw date string.
   *
   * @return \DateTimeImmutable|null
   *   A DateTimeImmutable object or NULL if invalid.
   */
  private function parseDate(?string $raw): ?\DateTimeImmutable {
    if (empty($raw)) {
      return NULL;
    }
    try {
      return new \DateTimeImmutable($raw);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Normalizes a blog node into an array for API output.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   Normalized blog data.
   */
  private function normalizeNode(NodeInterface $node): array {
    $body = $node->hasField('body') && !$node->get('body')->isEmpty()
      ? $node->get('body')->value
      : '';

    if ($node->hasField('field_published_date') && !$node->get('field_published_date')->isEmpty()) {
      $published_value = $node->get('field_published_date')->value;
      try {
        $published_dt = new \DateTimeImmutable($published_value);
        $published = $published_dt->format(DATE_ATOM);
      }
      catch (\Exception $e) {
        $published = (new \DateTimeImmutable("@{$node->getCreatedTime()}"))->format(DATE_ATOM);
      }
    }
    else {
      $published = (new \DateTimeImmutable("@{$node->getCreatedTime()}"))->format(DATE_ATOM);
    }

    $author = [
      'uid' => $node->getOwnerId(),
      'name' => $node->getOwner()->getDisplayName(),
    ];

    $tags_list = [];
    if ($node->hasField('field_blog_content_tag') && !$node->get('field_blog_content_tag')->isEmpty()) {
      foreach ($node->get('field_blog_content_tag')->referencedEntities() as $term) {
        if ($term instanceof TermInterface) {
          $tags_list[] = $term->label();
        }
      }
    }

    return [
      'nid' => $node->id(),
      'title' => $node->label(),
      'body' => $body,
      'published' => $published,
      'author' => $author,
      'tags' => $tags_list,
    ];
  }

}
