<?php


class SecureEmailRevisionMetadataEdited implements SecureEmailBody, PublicEmailBody {
  /** @var bool */
  public $isReadyToLand;
  /** @var bool */
  public $isTitleChanged;
  /** @var bool */
  public $isBugChanged;
  /** @var EmailRecipient|null */
  public $author;
  /** @var EmailMetadataEditedReviewer[] */
  public $reviewers;

  /**
   * @param bool $isReadyToLand
   * @param bool $isTitleChanged
   * @param bool $isBugChanged
   * @param EmailRecipient|null $author
   * @param EmailMetadataEditedReviewer[] $reviewers
   */
  public function __construct(bool $isReadyToLand, bool $isTitleChanged, bool $isBugChanged, ?EmailRecipient $author, array $reviewers) {
    $this->isReadyToLand = $isReadyToLand;
    $this->isTitleChanged = $isTitleChanged;
    $this->isBugChanged = $isBugChanged;
    $this->author = $author;
    $this->reviewers = $reviewers;
  }


  public static function from(ResolveUsers $resolveRecipients, ResolveRevisionStatus $resolveRevisionStatus, TransactionList $transactions, DifferentialRevision $rawRevision, PhabricatorUserStore $userStore, string $actorEmail) {
    $isTitleChanged = $transactions->containsType('differential.revision.title');
    $customFieldTx = $transactions->getTransactionWithType('core:customfield');
    if ($customFieldTx) {
      $isBugChanged = $customFieldTx->getMetadataValue('customfield:key') == 'differential:bugzilla-bug-id';
    } else {
      $isBugChanged = false;
    }

    $rawRevisionStatusTx = $transactions->getTransactionWithType('differential.revision.status');
    if ($rawRevisionStatusTx) {
      $oldRevisionStatus = $rawRevisionStatusTx->getOldValue();
      $newRevisionStatus = $rawRevisionStatusTx->getNewValue();
      $revisionChangedToNeedReview = $newRevisionStatus == 'needs-review' && $newRevisionStatus != $oldRevisionStatus;
    } else {
      $revisionChangedToNeedReview = false;
    }

    $reviewers = [];
    $rawReviewersTxs = $transactions->getAllTransactionsWithType('differential.revision.reviewers');
    if (!empty($rawReviewersTxs)) {
      $processedReviewerPHIDs = [];
      foreach ($rawReviewersTxs as $rawReviewersTx) {
        $reviewersTx = new ReviewersTransaction($rawReviewersTx);
        foreach ($reviewersTx->getAllUsers() as $reviewerPHID) {
          // When a user adds a reviewer, and an associated herald rule also adds a different reviewer, the first
          // reviewer will show up in both transactions.
          if (in_array($reviewerPHID, $processedReviewerPHIDs)) {
            continue;
          }
          $processedReviewerPHIDs[] = $reviewerPHID;
          $reviewers[] = EmailMetadataEditedReviewer::from($reviewerPHID, $rawRevision, $reviewersTx, $userStore, $revisionChangedToNeedReview, $actorEmail);
        }
      }
    } else {
      foreach ($resolveRecipients->resolveReviewers($revisionChangedToNeedReview) as $reviewer) {
        /** @var $reviewer EmailReviewer */
        $reviewers[] = new EmailMetadataEditedReviewer(
          $reviewer->name,
          $reviewer->isActionable,
          $reviewer->status,
          'no-change',
          $reviewer->recipients
        );
      }
    }

    return new SecureEmailRevisionMetadataEdited($resolveRevisionStatus->resolveIsReadyToLand(), $isTitleChanged, $isBugChanged, $resolveRecipients->resolveAuthorAsRecipient(), $reviewers);
  }
}