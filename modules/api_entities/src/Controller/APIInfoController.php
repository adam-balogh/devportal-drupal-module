<?php

namespace Drupal\devportal_api_entities\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityDescriptionInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\devportal_api_entities\APIInfoInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\devportal_api_entities\APIInfoStorageInterface;

/**
 * Returns responses for API Info routes.
 *
 * @package Drupal\devportal_api_entities\Controller
 */
class APIInfoController extends ControllerBase {

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * Constructs an APIInfoController object.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   */
  public function __construct(DateFormatterInterface $date_formatter, RendererInterface $renderer, EntityTypeBundleInfoInterface $entity_type_bundle_info) {
    $this->dateFormatter = $date_formatter;
    $this->renderer = $renderer;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    /** @var DateFormatterInterface $dateFormatter */
    $dateFormatter = $container->get('date.formatter');
    /** @var RendererInterface $renderer */
    $renderer = $container->get('renderer');
    /** @var EntityTypeBundleInfoInterface $entityTypeBundleInfo */
    $entityTypeBundleInfo = $container->get('entity_type.bundle.info');
    return new static($dateFormatter, $renderer, $entityTypeBundleInfo);
  }

  /**
   * Displays an API Info revision.
   *
   * @param int $api_info_revision
   *   The API Info revision ID.
   *
   * @return array
   *   An array suitable for drupal_render().
   */
  public function revisionShow($api_info_revision) {
    $api_info = $this->entityTypeManager()->getStorage('api_info')->loadRevision($api_info_revision);
    $view_builder = $this->entityTypeManager()->getViewBuilder('api_info');
    return $view_builder->view($api_info);
  }

  /**
   * Page title callback for an API Info revision.
   *
   * @param int $api_info_revision
   *   The API Info revision ID.
   *
   * @return string
   *   The page title.
   */
  public function revisionPageTitle($api_info_revision) {
    /** @var \Drupal\devportal_api_entities\Entity\APIInfo $api_info */
    $api_info = $this->entityTypeManager()->getStorage('api_info')->loadRevision($api_info_revision);
    return $this->t('Revision of %title from %date', ['%title' => $api_info->label(), '%date' => $this->dateFormatter->format($api_info->getRevisionCreationTime())]);
  }

  /**
   * Generates an overview table of older revisions of a API Info.
   *
   * @param \Drupal\devportal_api_entities\APIInfoInterface $api_info
   *   An API Info object.
   *
   * @return array
   *   An array as expected by drupal_render().
   */
  public function revisionOverview(APIInfoInterface $api_info) {
    $account = $this->currentUser();
    $langcode = $api_info->language()->getId();
    $langname = $api_info->language()->getName();
    $languages = $api_info->getTranslationLanguages();
    $has_translations = (count($languages) > 1);
    /** @var \Drupal\devportal_api_entities\APIInfoStorageInterface $api_info_storage */
    $api_info_storage = $this->entityTypeManager()->getStorage('api_info');

    $build['#title'] = $has_translations ? $this->t('@langname revisions for %title', ['@langname' => $langname, '%title' => $api_info->label()]) : $this->t('Revisions for %title', ['%title' => $api_info->label()]);
    $header = [$this->t('Revision'), $this->t('Operations')];

    $revert_permission = ($account->hasPermission("revert all api info revisions") || $account->hasPermission('administer api infos'));
    $delete_permission = ($account->hasPermission("delete all api info revisions") || $account->hasPermission('administer api infos'));

    $rows = [];
    $default_revision = $api_info->getRevisionId();

    foreach ($this->getRevisionIds($api_info, $api_info_storage) as $vid) {
      /** @var \Drupal\devportal_api_entities\APIInfoInterface $revision */
      $revision = $api_info_storage->loadRevision($vid);
      // Only show revisions that are affected by the language that is being
      // displayed.
      if ($revision->hasTranslation($langcode) && $revision->getTranslation($langcode)->isRevisionTranslationAffected()) {
        $username = [
          '#theme' => 'username',
          '#account' => $revision->getRevisionUser(),
        ];

        // Use revision link to link to revisions that are not active.
        $date = $this->dateFormatter->format($revision->getRevisionCreationTime(), 'short');
        if ($vid != $api_info->getRevisionId()) {
          $link = Link::fromTextAndUrl($date, new Url('entity.api_info.revision', ['api_info' => $api_info->id(), 'api_info_revision' => $vid]))->toString();
        }
        else {
          $link = $api_info->toLink($date)->toString();
        }

        $row = [];
        $column = [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '{% trans %}{{ date }} by {{ username }}{% endtrans %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}',
            '#context' => [
              'date' => $link,
              'username' => $this->renderer->renderPlain($username),
              'message' => ['#markup' => $revision->getRevisionLogMessage(), '#allowed_tags' => Xss::getHtmlTagList()],
            ],
          ],
        ];
        // @todo Simplify once https://www.drupal.org/node/2334319 lands.
        $this->renderer->addCacheableDependency($column['data'], $username);
        $row[] = $column;

        if ($vid == $default_revision) {
          $row[] = [
            'data' => [
              '#prefix' => '<em>',
              '#markup' => $this->t('Current revision'),
              '#suffix' => '</em>',
            ],
          ];

          $rows[] = [
            'data' => $row,
            'class' => ['revision-current'],
          ];
        }
        else {
          $links = [];
          if ($revert_permission) {
            $links['revert'] = [
              'title' => $vid < $api_info->getRevisionId() ? $this->t('Revert') : $this->t('Set as current revision'),
              'url' => $has_translations ?
                Url::fromRoute('entity.api_info.translation_revert', ['api_info' => $api_info->id(), 'api_info_revision' => $vid, 'langcode' => $langcode]) :
                Url::fromRoute('entity.api_info.revision_revert', ['api_info' => $api_info->id(), 'api_info_revision' => $vid]),
            ];
          }

          if ($delete_permission) {
            $links['delete'] = [
              'title' => $this->t('Delete'),
              'url' => Url::fromRoute('entity.api_info.revision_delete', ['api_info' => $api_info->id(), 'api_info_revision' => $vid]),
            ];
          }

          $row[] = [
            'data' => [
              '#type' => 'operations',
              '#links' => $links,
            ],
          ];

          $rows[] = $row;
        }
      }
    }

    $build['api_info_revisions_table'] = [
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => $header,
      '#attributes' => ['class' => 'api-info-revision-table'],
    ];

    $build['pager'] = ['#type' => 'pager'];

    return $build;
  }

  /**
   * Gets a list of API Info revision IDs for a specific API Info.
   *
   * @param \Drupal\devportal_api_entities\APIInfoInterface $api_info
   *   The API Info entity.
   * @param \Drupal\devportal_api_entities\APIInfoStorageInterface $api_info_storage
   *   The API Info storage handler.
   *
   * @return int[]
   *   API Info revision IDs (in descending order).
   */
  protected function getRevisionIds(APIInfoInterface $api_info, APIInfoStorageInterface $api_info_storage) {
    $result = $api_info_storage->getQuery()
      ->allRevisions()
      ->condition($api_info->getEntityType()->getKey('id'), $api_info->id())
      ->sort($api_info->getEntityType()->getKey('revision'), 'DESC')
      ->pager(50)
      ->execute();
    return array_keys($result);
  }

  /**
   * Displays add links for the available bundles.
   *
   * Redirects to the add form if there's only one bundle available.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|array
   *   If there's only one available bundle, a redirect response.
   *   Otherwise, a render array with the add links for each bundle.
   *
   * @see \Drupal\Core\Entity\Controller\EntityController::addPage()
   * The only changes to that method are:
   * - We want to have the original label, instead of the lowercased one.
   * - ControllerBase::entityTypeManager() should be used through this method,
   *   whilst EntityController->entityTypeManager is dependency-injected, so it
   *   can be used directly there.
   */
  public function addPage($entity_type_id) {
    $entity_type = $this->entityTypeManager()->getDefinition($entity_type_id);
    $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);
    $bundle_key = $entity_type->getKey('bundle');
    $bundle_entity_type_id = $entity_type->getBundleEntityType();
    $build = [
      '#theme' => 'entity_add_list',
      '#bundles' => [],
    ];
    if ($bundle_entity_type_id) {
      $bundle_argument = $bundle_entity_type_id;
      $bundle_entity_type = $this->entityTypeManager()->getDefinition($bundle_entity_type_id);
      $bundle_entity_type_label = $bundle_entity_type->getLabel();
      $build['#cache']['tags'] = $bundle_entity_type->getListCacheTags();

      // Build the message shown when there are no bundles.
      $link_text = $this->t('Add a new @entity_type.', ['@entity_type' => $bundle_entity_type_label]);
      $link_route_name = 'entity.' . $bundle_entity_type->id() . '.add_form';
      $build['#add_bundle_message'] = $this->t('There is no @entity_type yet. @add_link', [
        '@entity_type' => $bundle_entity_type_label,
        '@add_link' => Link::createFromRoute($link_text, $link_route_name)->toString(),
      ]);
      // Filter out the bundles the user doesn't have access to.
      $access_control_handler = $this->entityTypeManager()->getAccessControlHandler($entity_type_id);
      foreach ($bundles as $bundle_name => $bundle_info) {
        $access = $access_control_handler->createAccess($bundle_name, NULL, [], TRUE);
        if (!$access->isAllowed()) {
          unset($bundles[$bundle_name]);
        }
        $this->renderer->addCacheableDependency($build, $access);
      }
      // Add descriptions from the bundles.
      $bundles = $this->loadBundleDescriptions($bundles, $bundle_entity_type);
    }
    else {
      $bundle_argument = $bundle_key;
    }

    $form_route_name = 'entity.' . $entity_type_id . '.add_form';
    // Redirect if there's only one bundle available.
    if (count($bundles) == 1) {
      $bundle_names = array_keys($bundles);
      $bundle_name = reset($bundle_names);
      return $this->redirect($form_route_name, [$bundle_argument => $bundle_name]);
    }
    // Prepare the #bundles array for the template.
    foreach ($bundles as $bundle_name => $bundle_info) {
      $build['#bundles'][$bundle_name] = [
        'label' => $bundle_info['label'],
        'description' => isset($bundle_info['description']) ? $bundle_info['description'] : '',
        'add_link' => Link::createFromRoute($bundle_info['label'], $form_route_name, [$bundle_argument => $bundle_name]),
      ];
    }

    return $build;
  }

  /**
   * Expands the bundle information with descriptions, if known.
   *
   * @param array $bundles
   *   An array of bundle information.
   * @param \Drupal\Core\Entity\EntityTypeInterface $bundle_entity_type
   *   The bundle entity type definition.
   *
   * @return array
   *   The expanded array of bundle information.
   *
   * @see \Drupal\Core\Entity\Controller\EntityController::loadBundleDescriptions()
   * The only changes to that method is:
   * - ControllerBase::entityTypeManager() should be used through this method,
   *   whilst EntityController->entityTypeManager is dependency-injected, so it
   *   can be used directly there.
   */
  protected function loadBundleDescriptions(array $bundles, EntityTypeInterface $bundle_entity_type) {
    if (!$bundle_entity_type->entityClassImplements(EntityDescriptionInterface::class)) {
      return $bundles;
    }
    $bundle_names = array_keys($bundles);
    $storage = $this->entityTypeManager()->getStorage($bundle_entity_type->id());
    /** @var \Drupal\Core\Entity\EntityDescriptionInterface[] $bundle_entities */
    $bundle_entities = $storage->loadMultiple($bundle_names);
    foreach ($bundles as $bundle_name => &$bundle_info) {
      if (isset($bundle_entities[$bundle_name])) {
        $bundle_info['description'] = $bundle_entities[$bundle_name]->getDescription();
      }
    }

    return $bundles;
  }

}