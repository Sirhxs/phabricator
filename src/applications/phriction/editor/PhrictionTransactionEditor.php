<?php

final class PhrictionTransactionEditor
  extends PhabricatorApplicationTransactionEditor {

  private $description;
  private $oldContent;
  private $newContent;
  private $moveAwayDocument;
  private $skipAncestorCheck;
  private $contentVersion;
  private $processContentVersionError = true;

  public function setDescription($description) {
    $this->description = $description;
    return $this;
  }

  private function getDescription() {
    return $this->description;
  }

  private function setOldContent(PhrictionContent $content) {
    $this->oldContent = $content;
    return $this;
  }

  private function getOldContent() {
    return $this->oldContent;
  }

  private function setNewContent(PhrictionContent $content) {
    $this->newContent = $content;
    return $this;
  }

  private function getNewContent() {
    return $this->newContent;
  }

  public function setSkipAncestorCheck($bool) {
    $this->skipAncestorCheck = $bool;
    return $this;
  }

  public function getSkipAncestorCheck() {
    return $this->skipAncestorCheck;
  }

  public function setContentVersion($version) {
    $this->contentVersion = $version;
    return $this;
  }

  public function getContentVersion() {
    return $this->contentVersion;
  }

  public function setProcessContentVersionError($process) {
    $this->processContentVersionError = $process;
    return $this;
  }

  public function getProcessContentVersionError() {
    return $this->processContentVersionError;
  }

  public function getEditorApplicationClass() {
    return 'PhabricatorPhrictionApplication';
  }

  public function getEditorObjectsDescription() {
    return pht('Phriction Documents');
  }

  public function getTransactionTypes() {
    $types = parent::getTransactionTypes();

    $types[] = PhabricatorTransactions::TYPE_COMMENT;
    $types[] = PhrictionTransaction::TYPE_TITLE;
    $types[] = PhrictionTransaction::TYPE_CONTENT;
    $types[] = PhrictionTransaction::TYPE_DELETE;
    $types[] = PhrictionTransaction::TYPE_MOVE_TO;
    $types[] = PhrictionTransaction::TYPE_MOVE_AWAY;

    $types[] = PhabricatorTransactions::TYPE_VIEW_POLICY;
    $types[] = PhabricatorTransactions::TYPE_EDIT_POLICY;

    return $types;
  }

  protected function getCustomTransactionOldValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhrictionTransaction::TYPE_TITLE:
        if ($this->getIsNewObject()) {
          return null;
        }
        return $this->getOldContent()->getTitle();
      case PhrictionTransaction::TYPE_CONTENT:
        if ($this->getIsNewObject()) {
          return null;
        }
        return $this->getOldContent()->getContent();
      case PhrictionTransaction::TYPE_DELETE:
      case PhrictionTransaction::TYPE_MOVE_TO:
      case PhrictionTransaction::TYPE_MOVE_AWAY:
        return null;
    }
  }

  protected function getCustomTransactionNewValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhrictionTransaction::TYPE_TITLE:
      case PhrictionTransaction::TYPE_CONTENT:
      case PhrictionTransaction::TYPE_DELETE:
        return $xaction->getNewValue();
      case PhrictionTransaction::TYPE_MOVE_TO:
        $document = $xaction->getNewValue();
        // grab the real object now for the sub-editor to come
        $this->moveAwayDocument = $document;
        $dict = array(
          'id' => $document->getID(),
          'phid' => $document->getPHID(),
          'content' => $document->getContent()->getContent(),);
        return $dict;
      case PhrictionTransaction::TYPE_MOVE_AWAY:
        $document = $xaction->getNewValue();
        $dict = array(
          'id' => $document->getID(),
          'phid' => $document->getPHID(),
          'content' => $document->getContent()->getContent(),);
        return $dict;
    }
  }

  protected function shouldApplyInitialEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
      case PhrictionTransaction::TYPE_TITLE:
      case PhrictionTransaction::TYPE_CONTENT:
      case PhrictionTransaction::TYPE_DELETE:
      case PhrictionTransaction::TYPE_MOVE_TO:
      case PhrictionTransaction::TYPE_MOVE_AWAY:
        return true;
      }
    }
    return parent::shouldApplyInitialEffects($object, $xactions);
  }

  protected function applyInitialEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $this->setOldContent($object->getContent());
    $this->setNewContent($this->buildNewContentTemplate($object));
  }

  protected function applyCustomInternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhrictionTransaction::TYPE_TITLE:
      case PhrictionTransaction::TYPE_CONTENT:
      case PhrictionTransaction::TYPE_MOVE_TO:
        $object->setStatus(PhrictionDocumentStatus::STATUS_EXISTS);
        return;
      case PhrictionTransaction::TYPE_MOVE_AWAY:
        $object->setStatus(PhrictionDocumentStatus::STATUS_MOVED);
        return;
      case PhrictionTransaction::TYPE_DELETE:
        $object->setStatus(PhrictionDocumentStatus::STATUS_DELETED);
        return;
    }
  }

  protected function expandTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    $xactions = parent::expandTransaction($object, $xaction);
    switch ($xaction->getTransactionType()) {
      case PhrictionTransaction::TYPE_CONTENT:
        if ($this->getIsNewObject()) {
          break;
        }
        $content = $xaction->getNewValue();
        if ($content === '') {
          $xactions[] = id(new PhrictionTransaction())
            ->setTransactionType(PhrictionTransaction::TYPE_DELETE)
            ->setNewValue(true);
        }
        break;
      default:
        break;
    }

    return $xactions;
  }

  protected function applyCustomExternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhrictionTransaction::TYPE_TITLE:
        $this->getNewContent()->setTitle($xaction->getNewValue());
        break;
      case PhrictionTransaction::TYPE_CONTENT:
        $this->getNewContent()->setContent($xaction->getNewValue());
        break;
      case PhrictionTransaction::TYPE_DELETE:
        $this->getNewContent()->setContent('');
        $this->getNewContent()->setChangeType(
          PhrictionChangeType::CHANGE_DELETE);
        break;
      case PhrictionTransaction::TYPE_MOVE_TO:
        $dict = $xaction->getNewValue();
        $this->getNewContent()->setContent($dict['content']);
        $this->getNewContent()->setChangeType(
          PhrictionChangeType::CHANGE_MOVE_HERE);
        $this->getNewContent()->setChangeRef($dict['id']);
        break;
      case PhrictionTransaction::TYPE_MOVE_AWAY:
        $dict = $xaction->getNewValue();
        $this->getNewContent()->setContent('');
        $this->getNewContent()->setChangeType(
          PhrictionChangeType::CHANGE_MOVE_AWAY);
        $this->getNewContent()->setChangeRef($dict['id']);
        break;
      default:
        break;
    }
  }

  protected function applyFinalEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $save_content = false;
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhrictionTransaction::TYPE_TITLE:
        case PhrictionTransaction::TYPE_CONTENT:
        case PhrictionTransaction::TYPE_DELETE:
        case PhrictionTransaction::TYPE_MOVE_AWAY:
        case PhrictionTransaction::TYPE_MOVE_TO:
          $save_content = true;
          break;
        default:
          break;
      }
    }

    if ($save_content) {
      $content = $this->getNewContent();
      $content->setDocumentID($object->getID());
      $content->save();

      $object->setContentID($content->getID());
      $object->save();
      $object->attachContent($content);
    }

    if ($this->getIsNewObject() && !$this->getSkipAncestorCheck()) {
      // Stub out empty parent documents if they don't exist
      $ancestral_slugs = PhabricatorSlug::getAncestry($object->getSlug());
      if ($ancestral_slugs) {
        $ancestors = id(new PhrictionDocumentQuery())
          ->setViewer(PhabricatorUser::getOmnipotentUser())
          ->withSlugs($ancestral_slugs)
          ->needContent(true)
          ->execute();
        $ancestors = mpull($ancestors, null, 'getSlug');
        $stub_type = PhrictionChangeType::CHANGE_STUB;
        foreach ($ancestral_slugs as $slug) {
          $ancestor_doc = idx($ancestors, $slug);
          // We check for change type to prevent near-infinite recursion
          if (!$ancestor_doc && $content->getChangeType() != $stub_type) {
            $ancestor_doc = PhrictionDocument::initializeNewDocument(
              $this->getActor(),
              $slug);
            $stub_xactions = array();
            $stub_xactions[] = id(new PhrictionTransaction())
              ->setTransactionType(PhrictionTransaction::TYPE_TITLE)
              ->setNewValue(PhabricatorSlug::getDefaultTitle($slug))
              ->setMetadataValue('stub:create:phid', $object->getPHID());
            $stub_xactions[] = id(new PhrictionTransaction())
              ->setTransactionType(PhrictionTransaction::TYPE_CONTENT)
              ->setNewValue('')
              ->setMetadataValue('stub:create:phid', $object->getPHID());
            $sub_editor = id(new PhrictionTransactionEditor())
              ->setActor($this->getActor())
              ->setContentSource($this->getContentSource())
              ->setContinueOnNoEffect($this->getContinueOnNoEffect())
              ->setSkipAncestorCheck(true)
              ->setDescription(pht('Empty Parent Document'))
              ->applyTransactions($ancestor_doc, $stub_xactions);
          }
        }
      }
    }

    if ($this->moveAwayDocument !== null) {
      $move_away_xactions = array();
      $move_away_xactions[] = id(new PhrictionTransaction())
        ->setTransactionType(PhrictionTransaction::TYPE_MOVE_AWAY)
        ->setNewValue($object);
      $sub_editor = id(new PhrictionTransactionEditor())
        ->setActor($this->getActor())
        ->setContentSource($this->getContentSource())
        ->setContinueOnNoEffect($this->getContinueOnNoEffect())
        ->setDescription($this->getDescription())
        ->applyTransactions($this->moveAwayDocument, $move_away_xactions);
    }

    return $xactions;
  }

  protected function shouldSendMail(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function getMailSubjectPrefix() {
    return '[Phriction]';
  }

  protected function getMailTo(PhabricatorLiskDAO $object) {
    return array(
      $object->getContent()->getAuthorPHID(),
      $this->getActingAsPHID(),
    );
  }

  public function getMailTagsMap() {
    return array(
      PhrictionTransaction::MAILTAG_TITLE =>
        pht("A document's title changes."),
      PhrictionTransaction::MAILTAG_CONTENT =>
        pht("A document's content changes."),
      PhrictionTransaction::MAILTAG_DELETE =>
        pht('A document is deleted.'),
    );
  }

  protected function buildReplyHandler(PhabricatorLiskDAO $object) {
    return id(new PhrictionReplyHandler())
      ->setMailReceiver($object);
  }

  protected function buildMailTemplate(PhabricatorLiskDAO $object) {
    $id = $object->getID();
    $title = $object->getContent()->getTitle();

    return id(new PhabricatorMetaMTAMail())
      ->setSubject($title)
      ->addHeader('Thread-Topic', $object->getPHID());
  }

  protected function buildMailBody(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $body = parent::buildMailBody($object, $xactions);

    if ($this->getIsNewObject()) {
      $body->addTextSection(
        pht('DOCUMENT CONTENT'),
        $object->getContent()->getContent());
    }

    $body->addLinkSection(
      pht('DOCUMENT DETAIL'),
      PhabricatorEnv::getProductionURI(
        PhrictionDocument::getSlugURI($object->getSlug())));

    return $body;
  }

  protected function shouldPublishFeedStory(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return $this->shouldSendMail($object, $xactions);
  }

  protected function getFeedRelatedPHIDs(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $phids = parent::getFeedRelatedPHIDs($object, $xactions);

    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhrictionTransaction::TYPE_MOVE_TO:
          $dict = $xaction->getNewValue();
          $phids[] = $dict['phid'];
          break;
      }
    }

    return $phids;
  }

  protected function validateTransaction(
    PhabricatorLiskDAO $object,
    $type,
    array $xactions) {

    $errors = parent::validateTransaction($object, $type, $xactions);

    foreach ($xactions as $xaction) {
      switch ($type) {
        case PhrictionTransaction::TYPE_TITLE:
          $title = $object->getContent()->getTitle();
          $missing = $this->validateIsEmptyTextField(
            $title,
            $xactions);

          if ($missing) {
            $error = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht('Required'),
              pht('Document title is required.'),
              nonempty(last($xactions), null));

            $error->setIsMissingFieldError(true);
            $errors[] = $error;
          } else if ($this->getProcessContentVersionError()) {
            $error = $this->validateContentVersion($object, $type, $xaction);
            if ($error) {
              $this->setProcessContentVersionError(false);
              $errors[] = $error;
            }
          }
          break;

        case PhrictionTransaction::TYPE_CONTENT:
          if ($xaction->getMetadataValue('stub:create:phid')) {
            continue;
          }

          $missing = false;
          if ($this->getIsNewObject()) {
            $content = $object->getContent()->getContent();
            $missing = $this->validateIsEmptyTextField(
              $content,
              $xactions);
          }

          if ($missing) {
            $error = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht('Required'),
              pht('Document content is required.'),
              nonempty(last($xactions), null));

            $error->setIsMissingFieldError(true);
            $errors[] = $error;
          } else if ($this->getProcessContentVersionError()) {
            $error = $this->validateContentVersion($object, $type, $xaction);
            if ($error) {
              $this->setProcessContentVersionError(false);
              $errors[] = $error;
            }
          }
          break;

        case PhrictionTransaction::TYPE_MOVE_TO:
          $source_document = $xaction->getNewValue();
          switch ($source_document->getStatus()) {
            case PhrictionDocumentStatus::STATUS_DELETED:
              $e_text = pht('A deleted document can not be moved.');
              break;
            case PhrictionDocumentStatus::STATUS_MOVED:
              $e_text = pht('A moved document can not be moved again.');
              break;
            case PhrictionDocumentStatus::STATUS_STUB:
              $e_text = pht('A stub document can not be moved.');
              break;
            default:
              $e_text = null;
              break;
          }

          if ($e_text) {
            $error = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht('Can not move document.'),
              $e_text,
              $xaction);
            $errors[] = $error;
          }

          // NOTE: We use the ominpotent user because we can't let users
          // overwrite documents even if they can't see them.
          $target_document = id(new PhrictionDocumentQuery())
            ->setViewer(PhabricatorUser::getOmnipotentUser())
            ->withSlugs(array($object->getSlug()))
            ->needContent(true)
            ->executeOne();

          // Considering to overwrite existing docs? Nuke this!
          $exists = PhrictionDocumentStatus::STATUS_EXISTS;
          if ($target_document && $target_document->getStatus() == $exists) {
            $error = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht('Can not move document.'),
              pht('Can not overwrite existing target document.'),
              $xaction);
            $errors[] = $error;
          }
          break;

        case PhrictionTransaction::TYPE_DELETE:
          switch ($object->getStatus()) {
            case PhrictionDocumentStatus::STATUS_DELETED:
              $e_text = pht('An already deleted document can not be deleted.');
              break;
            case PhrictionDocumentStatus::STATUS_MOVED:
              $e_text = pht('A moved document can not be deleted.');
              break;
            case PhrictionDocumentStatus::STATUS_STUB:
              $e_text = pht('A stub document can not be deleted.');
              break;
            default:
              break 2;
          }

          $error = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Can not delete document.'),
            $e_text,
            $xaction);
          $errors[] = $error;
          break;
      }
    }

    return $errors;
  }

  private function validateContentVersion(
    PhabricatorLiskDAO $object,
    $type,
    PhabricatorApplicationTransaction $xaction) {

    $error = null;
    if ($this->getContentVersion() &&
       ($object->getContent()->getVersion() != $this->getContentVersion())) {
      $error = new PhabricatorApplicationTransactionValidationError(
        $type,
        pht('Edit Conflict'),
        pht(
          'Another user made changes to this document after you began '.
          'editing it. Do you want to overwrite their changes? '.
          '(If you choose to overwrite their changes, you should review '.
          'the document edit history to see what you overwrote, and '.
          'then make another edit to merge the changes if necessary.)'),
        $xaction);
    }
    return $error;
  }

  protected function supportsSearch() {
    return true;
  }

  protected function shouldApplyHeraldRules(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return false;
  }

  private function buildNewContentTemplate(
    PhrictionDocument $document) {

    $new_content = id(new PhrictionContent())
      ->setSlug($document->getSlug())
      ->setAuthorPHID($this->getActor()->getPHID())
      ->setChangeType(PhrictionChangeType::CHANGE_EDIT)
      ->setTitle($this->getOldContent()->getTitle())
      ->setContent($this->getOldContent()->getContent());
    if (strlen($this->getDescription())) {
      $new_content->setDescription($this->getDescription());
    }
    $new_content->setVersion($this->getOldContent()->getVersion() + 1);

    return $new_content;
  }

}
