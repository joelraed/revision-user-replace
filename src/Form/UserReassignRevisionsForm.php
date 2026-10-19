<?php

namespace Drupal\inside_user\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\inside_user\ReassignRevisionUser;
use Drupal\user\Entity\User;

/**
 * Lists and reassigns historical node and media revision authors.
 */
final class UserReassignRevisionsForm extends FormBase {

  protected int $id = 0;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'user_reassign_revisions_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $id = NULL): array {
    $this->id = (int) $id;
    $user = User::load($this->id);
    if (!$user) {
      $form['error'] = ['#markup' => $this->t('User not found.')];
      return $form;
    }

    $form['heading'] = [
      '#markup' => '<h2>' . $this->t('Reassign revisions for @user', ['@user' => $user->label()]) . '</h2>',
    ];
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Changes the revision author only. Content ownership and revision history are preserved. Back up the database before continuing.') . '</p>',
    ];
    $form['replace_user'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('Reassign to'),
      '#required' => TRUE,
    ];
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I have backed up the database and understand this modifies historical revision authors.'),
      '#required' => TRUE,
    ];

    $form['revisions'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Entity'),
        $this->t('Revision author field'),
        $this->t('Revision date'),
        $this->t('Revision ID'),
        $this->t('View'),
      ],
      '#empty' => $this->t('No matching revisions were found.'),
    ];

    $manager = \Drupal::entityTypeManager();
    $date_formatter = \Drupal::service('date.formatter');
    $matches = ReassignRevisionUser::getRevisions($this->id);
    $form['total'] = [
      '#markup' => '<p>' . $this->formatPlural(count($matches), '1 matching revision', '@count matching revisions') . '</p>',
      '#weight' => -1,
    ];

    foreach ($matches as $match) {
      $type = $match['entity_type'];
      $revision_id = $match['revision_id'];
      $revision = $manager->getStorage($type)->loadRevision($revision_id);
      if (!$revision) {
        continue;
      }
      $key = $type . ':' . $revision_id;
      $form['revisions'][$key]['entity'] = ['#plain_text' => $revision->label() ?? ''];
      $form['revisions'][$key]['field'] = ['#plain_text' => 'revision_uid'];
      $timestamp = method_exists($revision, 'getRevisionCreationTime')
        ? $revision->getRevisionCreationTime()
        : NULL;
      $form['revisions'][$key]['revision_date'] = [
        '#plain_text' => $timestamp ? $date_formatter->format($timestamp) : '',
      ];
      $form['revisions'][$key]['revision_id'] = ['#plain_text' => (string) $revision_id];

      try {
        $route_name = $type === 'media' ? 'entity.media.revision' : 'entity.node.revision';
        $route_params = $type === 'media'
          ? ['media' => $revision->id(), 'media_revision' => $revision_id]
          : ['node' => $revision->id(), 'node_revision' => $revision_id];
        $form['revisions'][$key]['view'] = [
          '#type' => 'link',
          '#title' => $this->t('View'),
          '#url' => Url::fromRoute($route_name, $route_params),
        ];
      }
      catch (\Exception $e) {
        $form['revisions'][$key]['view'] = ['#plain_text' => ''];
      }
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reassign Revisions'),
      '#disabled' => !$matches,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $replacement_id = (int) $form_state->getValue('replace_user');
    if ($replacement_id <= 0 || $replacement_id === $this->id || !User::load($replacement_id)) {
      $form_state->setErrorByName('replace_user', $this->t('Select a valid replacement user different from the original user.'));
    }
    if (!$form_state->getValue('confirm')) {
      $form_state->setErrorByName('confirm', $this->t('You must confirm the database backup before proceeding.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $count = ReassignRevisionUser::replaceUser(
      $this->id,
      (int) $form_state->getValue('replace_user')
    );
    // Direct SQL updates bypass entity cache invalidation.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    \Drupal::entityTypeManager()->getStorage('media')->resetCache();
    drupal_flush_all_caches();
    $this->messenger()->addStatus($this->formatPlural($count, '1 revision was updated.', '@count revisions were updated.'));
    $form_state->setRebuild(TRUE);
  }

}
