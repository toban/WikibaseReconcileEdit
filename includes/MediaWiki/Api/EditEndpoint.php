<?php

namespace MediaWiki\Extension\WikibaseReconcileEdit\MediaWiki\Api;

use DataValues\StringValue;
use MediaWiki\Extension\WikibaseReconcileEdit\EditStrategy\SimplePutStrategy;
use MediaWiki\Extension\WikibaseReconcileEdit\MediaWiki\ExternalLinks;
use MediaWiki\Extension\WikibaseReconcileEdit\MediaWiki\Request\EditRequest;
use MediaWiki\Extension\WikibaseReconcileEdit\MediaWiki\Request\MockEditDiskRequest;
use MediaWiki\Extension\WikibaseReconcileEdit\MediaWiki\Request\UrlInputEditRequest;
use MediaWiki\Rest\SimpleHandler;
use Title;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Lib\Store\EntityIdLookup;
use Wikibase\Repo\WikibaseRepo;
use Wikimedia\ParamValidator\ParamValidator;

class EditEndpoint extends SimpleHandler {

	private const VERSION_KEY = "wikibasereconcileedit-version";

	/**
	 * @var EntityIdLookup
	 */
	private $entityIdLookup;

	/**
	 * Get an EditRequest object from the current request
	 * @return EditRequest
	 */
	private function getEditRequest() : EditRequest {
		if ( isset( $_SERVER[ 'HTTP_X_WIKIBASERECONCILEEDIT_USE_DISK_REQUEST' ] ) ) {
			return new MockEditDiskRequest();
		}
		return new UrlInputEditRequest( $this->getRequest() );
	}

	public function run() {
		// TODO inject these services
		$repo = WikibaseRepo::getDefaultInstance();
		$propertyDataTypeLookup = $repo->getPropertyDataTypeLookup();
		$deserializer = $repo->getBaseDataModelDeserializerFactory()->newEntityDeserializer();
		$this->entityIdLookup = $repo->getEntityIdLookup();

		// Get the request
		$request = $this->getEditRequest();

		// Validate and process reconciliation input
		// TODO use different services per version
		// TODO output an object that controls the reconciliations spec?
		$inputReconcile = $request->reconcile();
		if ( $inputReconcile === null ) {
			die( 'Invalid reconcile JSON supplied' );
		}
		if (
			!array_key_exists( self::VERSION_KEY, $inputReconcile ) ||
			$inputReconcile[self::VERSION_KEY] !== '0.0.1'
		) {
			die( 'Only supported reconciliation version is 0.0.1' );
		}
		if (
			!array_key_exists( 'urlReconcile', $inputReconcile ) ||
			!preg_match( PropertyId::PATTERN, $inputReconcile['urlReconcile'] )
		) {
			die( '0.0.1 requires a single urlReconcile key mapped to a property id, such as P123' );
		}
		$reconcileUrlProperty = new PropertyId( $inputReconcile['urlReconcile'] );
		// For now this property must be of URL type
		if ( $propertyDataTypeLookup->getDataTypeIdForProperty( $reconcileUrlProperty ) !== 'url' ) {
			die( 'urlReconcile property must be of type url' );
		}

		// Validate entity input
		$inputEntity = $request->entity();
		if ( $inputEntity === null ) {
			die( 'Invalid entity JSON supplied' );
		}
		if ( !array_key_exists( self::VERSION_KEY, $inputEntity ) || $inputEntity[self::VERSION_KEY] !== '0.0.1' ) {
			die( 'Only supported entity version is 0.0.1' );
		}
		if ( !array_key_exists( 'type', $inputEntity ) || $inputEntity['type'] !== 'item' ) {
			die( 'Only supported entity type is \'item\'' );
		}
		/** @var Item $inputEntity */
		$inputEntity = $deserializer->deserialize( $inputEntity );

		// Validate Entity
		// Don't support references, qualifiers or sitelinks
		foreach ( $inputEntity->getStatements()->toArray() as $statement ) {
			if ( $statement->getQualifiers()->count() !== 0 || $statement->getReferences()->count() !== 0 ) {
				die( 'Qualifiers and References are not currently supported' );
			}
		}
		if ( $inputEntity->getSiteLinkList()->count() !== 0 ) {
			die( 'Sitelinks are not currently supported' );
		}
		// Check for our reconciliation value
		$reconciliationStatements = $inputEntity->getStatements()->getByPropertyId( $reconcileUrlProperty );
		if ( $reconciliationStatements->count() !== 1 ) {
			die( 'Entity must have at least one statement for the reconciliation Property' );
		}
		$reconciliationStatement = $reconciliationStatements->toArray()[0];
		if ( !$reconciliationStatement->getMainSnak() instanceof PropertyValueSnak ) {
			die( 'Reconciliation statement must be of type value ' );
		}
		/** @var PropertyValueSnak $reconciliationMainSnak */
		$reconciliationMainSnak = $reconciliationStatement->getMainSnak();
		/** @var StringValue $reconciliationDataValue */
		$reconciliationDataValue = $reconciliationMainSnak->getDataValue();
		$reconciliationUrl = $reconciliationDataValue->getValue();

		// Find Items that use the URL
		$itemIdsThatReferenceTheUrl = $this->getItemIdsFromPageIds(
			( new ExternalLinks() )->pageIdsContainingUrl( $reconciliationUrl )
		);

		// Find Items that match the URL and Property ID
		$itemsThatReferenceTheUrlInCorrectStatement = [];
		foreach ( $itemIdsThatReferenceTheUrl as $itemId ) {
			/** @var Item $item */
			$item = $repo->getEntityLookup()->getEntity( $itemId );
			foreach ( $item->getStatements()->getByPropertyId( $reconcileUrlProperty )->toArray() as $statement ) {
				if ( !$statement->getMainSnak() instanceof PropertyValueSnak ) {
					continue;
				}
				/** @var PropertyValueSnak $mainSnak */
				$mainSnak = $statement->getMainSnak();
				$urlOfStatement = $mainSnak->getDataValue()->getValue();
				if ( $urlOfStatement === $reconciliationUrl ) {
					$itemsThatReferenceTheUrlInCorrectStatement[] = $item;
				}
			}
		}

		// If we have more than one item matches, something is wrong and we can't edit
		if ( count( $itemsThatReferenceTheUrlInCorrectStatement ) > 1 ) {
			die( 'Matched multiple Items during reconciliation :(' );
		}

		// Get our base
		if ( count( $itemsThatReferenceTheUrlInCorrectStatement ) === 1 ) {
			$base = $itemsThatReferenceTheUrlInCorrectStatement[0];
			// XXX: This bit is so annoying...
			$baseRevId = $repo->getEntityRevisionLookup()
				->getLatestRevisionId( $base->getId() )
				->onConcreteRevision( function ( $revId ) {
					return $revId;
				} )
				->onRedirect( function () {
					throw new \RuntimeException();
				} )
				->onNonexistentEntity( function () {
					throw new \RuntimeException();
				} )
				->map();
		} else {
			$base = new Item();
			$baseRevId = false;
			// XXX: this is a bit evil, but needed to work around the fact we want to mint statement guids
			$base->setId( ItemId::newFromNumber( $repo->newIdGenerator()->getNewId( 'wikibase-item' ) ) );
		}

		// And make the edit
		$toSave = ( new SimplePutStrategy() )->apply( $base, $inputEntity );
		$editEntity = $repo->newEditEntityFactory()->newEditEntity(
			// TODO use a real user
			\User::newSystemUser( 'WikibaseReconcileEditReconciliator' ),
			$toSave->getId(),
			$baseRevId
		);
		$saveStatus = $editEntity->attemptSave(
			$toSave,
			'Reconciliation Edit',
			$baseRevId ? null : EDIT_NEW,
			// TODO actually do a token check?
			false
		);

		// Make some sort of response
		$response = [
			'success' => $saveStatus->isGood()
		];
		return json_encode( $response );
	}

	/**
	 * @param int[] $pageIds
	 * @return ItemId[]
	 */
	private function getItemIdsFromPageIds( array $pageIds ) : array {
		$itemIds = [];
		foreach ( $pageIds as $pageId ) {
			$entityId = $this->entityIdLookup->getEntityIdForTitle( Title::newFromID( $pageId ) );
			if ( $entityId && $entityId instanceof ItemId ) {
				$itemIds[] = $entityId;
			}
		}
		return $itemIds;
	}

	public function needsWriteAccess() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 * @return array
	 */
	public function getParamSettings() {
		return [
			'entity' => [
				self::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'reconcile' => [
				self::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
