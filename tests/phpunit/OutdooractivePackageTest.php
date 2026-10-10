<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Metadata\TemplateParser;
use WP_Scatter_Elsewhere\Outdooractive\ActivityResolver;
use WP_Scatter_Elsewhere\Outdooractive\GpxEnricher;
use WP_Scatter_Elsewhere\Outdooractive\GpxLinks;
use WP_Scatter_Elsewhere\Outdooractive\PackageBuilder;
use WP_Scatter_Elsewhere\Outdooractive\PackageSettings;

class OutdooractivePackageTest extends TestCase {

	private const PHONETRACK = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="no" ?>
<gpx xmlns="http://www.topografix.com/GPX/1/1" xmlns:gpxtpx="http://www.garmin.com/xmlschemas/TrackPointExtension/v1" creator="PhoneTrack Nextcloud app 0.7.7" version="1.1">
<metadata>
 <time>2026-10-08T17:50:22Z</time>
 <name>s25</name>
 <desc>1 device</desc>
</metadata>
<trk>
 <name>s25</name>
 <trkseg>
  <trkpt lat="45.14847642" lon="2.49901294">
   <time>2026-10-08T08:23:41Z</time>
   <ele>1031.35</ele>
   <extensions><speed>4.520</speed></extensions>
  </trkpt>
 </trkseg>
</trk>
</gpx>
XML;

	public function test_the_name_and_description_replace_those_of_the_recorder_in_the_metadata_and_the_track(): void {
		$xml = ( new GpxEnricher() )->enrich( self::PHONETRACK, 'Salers ⇾ Pérols', "Une étape & <un> détour.\n\nSuite." );

		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		$xp = new DOMXPath( $dom );
		$xp->registerNamespace( 'g', 'http://www.topografix.com/GPX/1/1' );

		$this->assertSame( 'Salers ⇾ Pérols', $xp->evaluate( 'string(/g:gpx/g:metadata/g:name)' ) );
		$this->assertSame( "Une étape & <un> détour.\n\nSuite.", $xp->evaluate( 'string(/g:gpx/g:metadata/g:desc)' ) );
		$this->assertSame( 'Salers ⇾ Pérols', $xp->evaluate( 'string(/g:gpx/g:trk/g:name)' ) );
		$this->assertSame( "Une étape & <un> détour.\n\nSuite.", $xp->evaluate( 'string(/g:gpx/g:trk/g:desc)' ) );
		$this->assertSame( 1.0, $xp->evaluate( 'count(/g:gpx/g:metadata/g:name)' ) );
		$this->assertSame( 1.0, $xp->evaluate( 'count(/g:gpx/g:trk/g:trkseg/g:trkpt)' ), 'The points are kept.' );
		$this->assertSame( '1031.35', $xp->evaluate( 'string(//g:ele)' ) );
		$this->assertSame( 'PhoneTrack Nextcloud app 0.7.7', $dom->documentElement->getAttribute( 'creator' ) );
		$this->assertStringContainsString( '<speed>4.520</speed>', $xml );
		$this->assertStringContainsString( 'xmlns:gpxtpx=', $xml );
	}

	public function test_missing_elements_are_created_in_the_order_of_the_schema(): void {
		$bare = '<?xml version="1.0"?><gpx xmlns="http://www.topografix.com/GPX/1/1" version="1.1" creator="x"><trk><cmt>c</cmt><trkseg><trkpt lat="1" lon="2"/></trkseg></trk></gpx>';
		$xml  = ( new GpxEnricher() )->enrich( $bare, 'Titre', 'Texte' );

		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		$xp = new DOMXPath( $dom );
		$xp->registerNamespace( 'g', 'http://www.topografix.com/GPX/1/1' );

		$this->assertSame( 'metadata', $dom->documentElement->firstChild->localName );
		$this->assertSame( [ 'name', 'cmt', 'desc', 'trkseg' ], array_map( static fn( $n ) => $n->localName, iterator_to_array( $xp->query( '/g:gpx/g:trk/*' ) ) ) );
		$this->assertSame( [ 'name', 'desc' ], array_map( static fn( $n ) => $n->localName, iterator_to_array( $xp->query( '/g:gpx/g:metadata/*' ) ) ) );
	}

	public function test_something_that_is_not_gpx_is_refused(): void {
		foreach ( [ '', 'not xml', '<html><body/></html>' ] as $content ) {
			try {
				( new GpxEnricher() )->enrich( $content, 'a', 'b' );
				$this->fail( 'Expected a refusal for: ' . $content );
			} catch ( InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_it_does_not_load_external_entities(): void {
		$xxe = '<?xml version="1.0"?><!DOCTYPE gpx [<!ENTITY x SYSTEM "file:///etc/hostname">]><gpx xmlns="http://www.topografix.com/GPX/1/1"><trk><name>&x;</name></trk></gpx>';

		$this->expectException( InvalidArgumentException::class );
		( new GpxEnricher() )->enrich( $xxe, 'Titre', 'Texte' );
	}

	public function test_finds_the_gpx_links_of_a_post(): void {
		$html = '<p>Download file: <a href="https://e.vli.st/wp-content/uploads/photos/2026/eric/10/08/20261008-vanlife.gpx">x</a></p>'
			. '<a href="/uploads/Autre.GPX?v=2">y</a><a href="/doc.pdf">z</a><a href="https://e.vli.st/wp-content/uploads/photos/2026/eric/10/08/20261008-vanlife.gpx">again</a><a>no href</a>';

		$this->assertSame(
			[ 'https://e.vli.st/wp-content/uploads/photos/2026/eric/10/08/20261008-vanlife.gpx', '/uploads/Autre.GPX?v=2' ],
			( new GpxLinks() )->find( $html )
		);
		$this->assertSame( [], ( new GpxLinks() )->find( '' ) );
	}

	public function test_the_activity_comes_from_the_file_then_its_name_then_the_default(): void {
		$resolver = new ActivityResolver();
		$suffixes = [ 'vanlife' => 'Camping-car', 'velo' => 'Cycling', 'rando' => 'Hiking' ];

		$this->assertSame( 'Mountaineering', $resolver->resolve( 'Mountaineering', '20261008-vanlife.gpx', $suffixes, 'Hiking' ) );
		$this->assertSame( 'Camping-car', $resolver->resolve( null, '20261008-vanlife.gpx', $suffixes, 'Hiking' ) );
		$this->assertSame( 'Cycling', $resolver->resolve( '', '2026-10-08_Velo.GPX', $suffixes, 'Hiking' ) );
		$this->assertSame( 'Camping-car', $resolver->resolve( null, 'vanlife.gpx', $suffixes, 'Hiking' ) );
		$this->assertSame( 'Running', $resolver->resolve( null, '20261008-ski.gpx', $suffixes, 'Running' ), 'No match: the default.' );
		$this->assertSame( 'Hiking', $resolver->resolve( null, '20261008-vanlifex.gpx', $suffixes, 'Hiking' ), 'The end of the name must be the suffix.' );
	}

	public function test_a_folder_name_is_one_plain_segment(): void {
		$resolver = new ActivityResolver();

		$this->assertSame( 'Camping-car', $resolver->clean( ' Camping-car ' ) );
		$this->assertSame( 'a b', $resolver->clean( '../a/b' ) );
		$this->assertSame( '', $resolver->clean( '..' ) );
		$this->assertSame( 'x y', $resolver->clean( "x\0\\y" ) );
	}

	public function test_the_package_has_one_folder_per_activity_and_unique_names(): void {
		$written = null;
		$builder = new PackageBuilder( static function ( string $path, array $entries ) use ( &$written ): void {
			$written = [ $path, $entries ];
		} );

		$paths = $builder->build(
			'/tmp/x.zip',
			[
				[ 'folder' => 'Camping-car', 'name' => '20261008-vanlife.gpx', 'content' => 'A' ],
				[ 'folder' => 'Camping-car', 'name' => '20261008-vanlife.gpx', 'content' => 'B' ],
				[ 'folder' => '', 'name' => '../évasion.gpx', 'content' => 'C' ],
				[ 'folder' => 'Cycling', 'name' => '', 'content' => 'D' ],
			]
		);

		$this->assertSame( [ 'Camping-car/20261008-vanlife.gpx', 'Camping-car/20261008-vanlife-2.gpx', 'Hiking/évasion.gpx', 'Cycling/track.gpx' ], $paths );
		$this->assertSame( '/tmp/x.zip', $written[0] );
		$this->assertSame( 'B', $written[1]['Camping-car/20261008-vanlife-2.gpx'] );
	}

	private function settings( mixed &$stored ): PackageSettings {
		return new PackageSettings( static function () use ( &$stored ) { return $stored; }, static function ( array $v ) use ( &$stored ): void { $stored = $v; }, new TemplateParser(), new ActivityResolver() );
	}

	public function test_the_settings_have_defaults_validate_the_templates_and_parse_the_suffixes(): void {
		$stored   = false;
		$settings = $this->settings( $stored );

		$this->assertSame( '{title}', $settings->titleTemplate() );
		$this->assertSame( "{excerpt}\n\n{permalink}", $settings->descriptionTemplate() );
		$this->assertSame( 'Hiking', $settings->defaultActivity() );
		$this->assertSame( [], $settings->suffixes() );

		$settings->save( "{title}\n(Vanlife)", '{section:Le Carnet de voyage}', ' Camping-car ', "-Vanlife = Camping-car\nvelo=Cycling\nnothing\n = x\n" );

		$this->assertSame( '{title} (Vanlife)', $settings->titleTemplate() );
		$this->assertSame( '{section:Le Carnet de voyage}', $settings->descriptionTemplate() );
		$this->assertSame( 'Camping-car', $settings->defaultActivity() );
		$this->assertSame( [ 'vanlife' => 'Camping-car', 'velo' => 'Cycling' ], $settings->suffixes() );
		$this->assertSame( "vanlife = Camping-car\nvelo = Cycling", $settings->suffixesText() );

		$before = $stored;
		try {
			$settings->save( '{nope}', 'x', 'Hiking', '' );
			$this->fail( 'An invalid template must be refused.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( $before, $stored, 'Nothing is saved.' );
		}
	}
}
