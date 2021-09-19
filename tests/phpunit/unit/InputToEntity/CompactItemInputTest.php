<?php

namespace MediaWiki\Extension\WikibaseReconcileEdit\Tests\Unit\InputToEntity;

use MediaWiki\Extension\WikibaseReconcileEdit\InputToEntity\CompactItemInput;
use MediaWiki\Extension\WikibaseReconcileEdit\Reconciliation\ReconciliationService;
use MediaWiki\Extension\WikibaseReconcileEdit\ReconciliationException;
use MediaWiki\Extension\WikibaseReconcileEdit\Wikibase\FluidItem;
use ValueParsers\StringParser;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookup;
use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookupException;
use Wikibase\DataModel\Services\Term\PropertyLabelResolver;
use Wikibase\Repo\ValueParserFactory;

/**
 * @covers \MediaWiki\Extension\WikibaseReconcileEdit\InputToEntity\CompactItemInput
 * @license GPL-2.0-or-later
 */
class CompactItemInputTest extends \MediaWikiUnitTestCase {

	public const VERSION_NAME = '0.0.1/compact';

	private function mockPropertyDataTypeLookup() {
		$mock = $this->createMock( PropertyDataTypeLookup::class );
		$mock->method( 'getDataTypeIdForProperty' )
			->willReturnCallback( function ( EntityId $id ) {
				return $id->getSerialization() . '-data-type';
			} );
		return $mock;
	}

	private function mockPropertyLabelResolver(): PropertyLabelResolver {
		$mock = $this->createMock( PropertyLabelResolver::class );
		$mock->expects( $this->never() )
			->method( 'getPropertyIdsForLabels' );
		return $mock;
	}

	private function mockValueParserFactory() {
		$mock = $this->createMock( ValueParserFactory::class );
		$mock->method( 'newParser' )
			->willReturnCallback( function () {
				return new StringParser();
			} );
		return $mock;
	}

	public function provideTestGetItem() {
		yield 'Empty' => [
			[
				'wikibasereconcileedit-version' => self::VERSION_NAME,
			],
			new Item()
		];
		yield '1 Label' => [
			[
				'wikibasereconcileedit-version' => self::VERSION_NAME,
				'labels' => [ 'en-label' ],
			],
			FluidItem::init()
				->withLabel( 'en', 'en-label' )
				->item()
		];
		yield 'Full' => [
			[
				'wikibasereconcileedit-version' => self::VERSION_NAME,
				'statements' => [
					[
						'P23' => 'im-a-string',
					],
				],
			],
			FluidItem::init()
				->withLabel( 'en', 'en-label' )
				->withStringValue( 'P23', 'im-a-string' )
				->item()
		];
	}

	/**
	 * @dataProvider provideTestGetItem
	 */
	public function testGetItem( array $requestEntity, Item $expected ) {
		$sut = new CompactItemInput(
			$this->mockPropertyDataTypeLookup(),
			$this->mockValueParserFactory(),
			$this->mockReconciliationService(),
			$this->mockPropertyLabelResolver()
		);

		$prop = new PropertyId( 'P23' );

		$newItems = $sut->getItem( $requestEntity, $prop );
		$new = $newItems[0];

		$this->assertTrue(
			$new->equals( $expected ),
			'Expected:' . PHP_EOL . var_export( $expected, true ) . PHP_EOL . PHP_EOL .
			'Actual:' . PHP_EOL . var_export( $new, true )
		);
	}

	private function mockReconciliationService() {
		$mock = $this->createMock( ReconciliationService::class );
		return $mock;
	}

	public function testStatementsHavePropertyKey() {
		$requestEntityMissingProperty = [
			'wikibasereconcileedit-version' => '0.0.1/minimal',
			'statements' => [
				[
					'value' => 'im-a-string',
				],
			],
		];
		$sut = new CompactItemInput(
			$this->mockPropertyDataTypeLookup(),
			$this->mockValueParserFactory(),
			$this->mockReconciliationService(),
			$this->mockPropertyLabelResolver(),
		);

		$prop = new PropertyId( 'P23' );

		try {
			$new = $sut->getItem( $requestEntityMissingProperty, $prop );
			$this->fail( 'expected ReconciliationException to be thrown' );
		} catch ( ReconciliationException $rex ) {
			$this->assertSame( 'wikibasereconcileedit-statements-missing-keys',
				$rex->getMessageValue()->getKey() );
		}
	}

	public function testStatementsHaveValueKey() {
		$requestEntityMissingValue = [
			'wikibasereconcileedit-version' => '0.0.1/minimal',
			'statements' => [
				[
					'property' => 'P23',
				],
			],
		];
		$sut = new CompactItemInput(
			$this->mockPropertyDataTypeLookup(),
			$this->mockValueParserFactory(),
			$this->mockReconciliationService(),
			$this->mockPropertyLabelResolver(),
		);

		$prop = new PropertyId( 'P23' );

		try {
			$new = $sut->getItem( $requestEntityMissingValue, $prop );
			$this->fail( 'expected ReconciliationException to be thrown' );
		} catch ( ReconciliationException $rex ) {
			$this->assertSame( 'wikibasereconcileedit-statements-missing-keys',
				$rex->getMessageValue()->getKey() );
		}
	}

	public function testPropertyDatatypeNotFound() {
		$prop = new PropertyId( 'P23' );

		$mockLookup = $this->createMock( PropertyDataTypeLookup::class );
		$mockLookup->method( 'getDataTypeIdForProperty' )
			->willThrowException( new PropertyDataTypeLookupException( $prop ) );

		$sut = new CompactItemInput(
			$mockLookup,
			$this->mockValueParserFactory(),
			$this->mockReconciliationService(),
			$this->mockPropertyLabelResolver(),
		);
		$this->expectException( ReconciliationException::class );
		$this->expectExceptionMessage( 'wikibasereconcileedit-property-datatype-lookup-error' );

		$sut->getItem( [ 'statements' => [ [ 'property' => 'P1', 'value' => 'whatever' ] ] ], $prop );
	}

	public function testGetPropertyByPropertyID() {
		$sut = new CompactItemInput(
			$this->mockPropertyDataTypeLookup(),
			$this->mockValueParserFactory(),
			$this->mockReconciliationService(),
			$this->mockPropertyLabelResolver(),
		);

		$prop = new PropertyId( 'P23' );

		$this->assertEquals( $prop, $sut->getPropertyId( 'P23' ) );
	}

	public function testGetPropertyIdByLabelNothingFound() {
		$exceptionMessageKey = 'wikibasereconcileedit-property-not-found';
		$propertyIdsArray = [];
		$propByLabel = 'im-a-label';
		$propertyLabelResolver = $this->createMock( PropertyLabelResolver::class );
		$propertyLabelResolver->method( 'getPropertyIdsForLabels' )
			->willReturn( $propertyIdsArray );

		$sut = new CompactItemInput(
			$this->mockPropertyDataTypeLookup(),
			$this->mockValueParserFactory(),
			$this->mockReconciliationService(),
			$propertyLabelResolver,
		);

		try{
			$sut->getPropertyId( $propByLabel );
			$this->fail( 'expected ReconciliationException to be thrown' );
		} catch ( ReconciliationException $rex ) {
			$this->assertSame( $exceptionMessageKey, $rex->getMessageValue()->getKey() );
		}
	}

	public function testGetPropertyIdByLabel() {
		$propByLabel = 'im-a-label';
		$expectedPropertyID = new PropertyId( 'P1234' );
		$propertyLabelResolver = $this->createMock( PropertyLabelResolver::class );
		$propertyLabelResolver->method( 'getPropertyIdsForLabels' )
			->with( [ $propByLabel ] )
			->willReturn( [ $propByLabel => $expectedPropertyID ] );

		$sut = new CompactItemInput(
			$this->mockPropertyDataTypeLookup(),
			$this->mockValueParserFactory(),
			$this->mockReconciliationService(),
			$propertyLabelResolver,
		);

		$new = $sut->getPropertyId( $propByLabel );
		$this->assertEquals( $expectedPropertyID, $new );
	}
}
