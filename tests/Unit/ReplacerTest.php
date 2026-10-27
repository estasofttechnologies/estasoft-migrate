<?php
namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Replace\Replacer;
use Estasoft\Migrate\Replace\Serialized;
use PHPUnit\Framework\TestCase;

class ReplacerTest extends TestCase {

	private function replacer( $from = 'http://old.example.com', $to = 'https://new.example.org', array $opts = array() ) {
		return new Replacer( array( array( $from, $to ) ), $opts );
	}

	public function test_plain_text_is_replaced(): void {
		$r = $this->replacer();
		$this->assertSame( 'Visit https://new.example.org/about now', $r->replace( 'Visit http://old.example.com/about now' ) );
		$this->assertSame( 1, $r->stats()['matches'] );
		$this->assertSame( 0, $r->stats()['nested'] );
	}

	public function test_value_without_match_is_returned_untouched(): void {
		$r = $this->replacer();
		$value = serialize( array( 'a' => 'nothing here' ) );
		$this->assertSame( $value, $r->replace( $value ) );
		$this->assertSame( 0, $r->stats()['matches'] );
	}

	public function test_serialized_string_length_is_recalculated(): void {
		$r        = $this->replacer();
		$original = serialize( array( 'home' => 'http://old.example.com', 'count' => 3, 'on' => true, 'none' => null, 'ratio' => 1.5 ) );
		$result   = $r->replace( $original );

		$this->assertStringContainsString( 's:23:"https://new.example.org";', $result );
		$data = unserialize( $result ); // The result must be valid for PHP itself.
		$this->assertSame( 'https://new.example.org', $data['home'] );
		$this->assertSame( 3, $data['count'] );
		$this->assertTrue( $data['on'] );
		$this->assertNull( $data['none'] );
		$this->assertSame( 1.5, $data['ratio'] );
		$this->assertSame( 1, $r->stats()['nested'] );
	}

	public function test_multibyte_lengths_are_counted_in_bytes(): void {
		$r      = $this->replacer( 'cafe', 'café' );
		$result = $r->replace( serialize( array( 'a' => 'my cafe' ) ) );
		$this->assertSame( array( 'a' => 'my café' ), unserialize( $result ) );
		$this->assertStringContainsString( 's:8:"my café";', $result );
	}

	public function test_nested_serialized_inside_serialized(): void {
		$r      = $this->replacer();
		$inner  = serialize( array( 'url' => 'http://old.example.com/x' ) );
		$outer  = serialize( array( 'blob' => $inner ) );
		$result = $r->replace( $outer );
		$blob   = unserialize( $result )['blob'];
		$this->assertSame( array( 'url' => 'https://new.example.org/x' ), unserialize( $blob ) );
	}

	public function test_json_inside_serialized_and_serialized_inside_json(): void {
		$r      = $this->replacer();
		$json   = json_encode( array( 'u' => 'http://old.example.com/z', 'n' => 1 ) );
		$result = $r->replace( serialize( array( 'cfg' => $json ) ) );
		$this->assertSame( array( 'u' => 'https://new.example.org/z', 'n' => 1 ), json_decode( unserialize( $result )['cfg'], true ) );

		$doc    = json_encode( array( 'data' => serialize( array( 'p' => 'http://old.example.com/p' ) ) ) );
		$result = $r->replace( $doc );
		$this->assertSame( array( 'p' => 'https://new.example.org/p' ), unserialize( json_decode( $result, true )['data'] ) );
	}

	public function test_json_formatting_is_preserved(): void {
		$r      = $this->replacer();
		$source = "{\n  \"url\": \"http:\\/\\/old.example.com\\/a\",\n  \"keep\": 1.0,\n  \"name\": \"\\u00e9t\\u00e9\"\n}";
		$result = $r->replace( $source );
		$this->assertSame( "{\n  \"url\": \"https:\\/\\/new.example.org\\/a\",\n  \"keep\": 1.0,\n  \"name\": \"\\u00e9t\\u00e9\"\n}", $result );
	}

	public function test_json_escaped_slashes_in_plain_text_are_found(): void {
		$r      = $this->replacer();
		$post   = '<!-- wp:image {"url":"http:\/\/old.example.com\/a.png"} --><img src="http://old.example.com/a.png"/><!-- /wp:image -->';
		$result = $r->replace( $post );
		$this->assertStringNotContainsString( 'old.example.com', $result );
		$this->assertStringContainsString( '{"url":"https:\/\/new.example.org\/a.png"}', $result );
		$this->assertStringContainsString( 'src="https://new.example.org/a.png"', $result );
	}

	public function test_objects_are_never_created_and_class_names_are_kept(): void {
		$r      = $this->replacer();
		$source = 'O:8:"Missing_":2:{s:3:"url";s:22:"http://old.example.com";s:4:"keep";i:7;}';
		$result = $r->replace( $source );
		$this->assertSame( 'O:8:"Missing_":2:{s:3:"url";s:23:"https://new.example.org";s:4:"keep";i:7;}', $result );
	}

	public function test_object_property_names_are_not_rewritten(): void {
		$r      = $this->replacer( 'secret', 'shown' );
		$source = "O:3:\"Foo\":1:{s:9:\"\0*\0secret\";s:6:\"secret\";}";
		$result = $r->replace( $source );
		$this->assertSame( "O:3:\"Foo\":1:{s:9:\"\0*\0secret\";s:5:\"shown\";}", $result );
	}

	public function test_array_keys_are_rewritten_by_default_and_can_be_kept(): void {
		$source = serialize( array( 'http://old.example.com' => 'x' ) );
		$this->assertSame( array( 'https://new.example.org' => 'x' ), unserialize( $this->replacer()->replace( $source ) ) );
		$this->assertSame( array( 'http://old.example.com' => 'x' ), unserialize( $this->replacer( 'http://old.example.com', 'https://new.example.org', array( 'replace_keys' => false ) )->replace( $source ) ) );
	}

	public function test_references_and_custom_serialization_survive(): void {
		$r      = $this->replacer();
		$source = 'a:3:{i:0;s:22:"http://old.example.com";i:1;r:2;i:2;C:11:"ArrayObject":3:{xyz}}';
		$result = $r->replace( $source );
		$this->assertSame( 'a:3:{i:0;s:23:"https://new.example.org";i:1;r:2;i:2;C:11:"ArrayObject":3:{xyz}}', $result );
	}

	public function test_corrupt_serialized_data_falls_back_to_text_replacement(): void {
		$r      = $this->replacer();
		$source = 's:99:"http://old.example.com";'; // Wrong length on purpose.
		$result = $r->replace( $source );
		$this->assertSame( 's:99:"https://new.example.org";', $result );
		$this->assertSame( 1, $r->stats()['unparsed'] );
	}

	public function test_case_insensitive_mode(): void {
		$r = $this->replacer( 'Old.Example.COM', 'new.example.org', array( 'case_sensitive' => false ) );
		$this->assertSame( 'http://new.example.org/ and new.example.org', $r->replace( 'http://old.example.com/ and OLD.EXAMPLE.COM' ) );
		$this->assertSame( 2, $r->stats()['matches'] );
	}

	public function test_regex_mode_with_backreferences(): void {
		$r = new Replacer( array( array( 'https?://old\.example\.com(/[a-z]+)?', 'https://new.example.org$1' ) ), array( 'regex' => true ) );
		$this->assertSame( 'https://new.example.org/blog and https://new.example.org', $r->replace( 'http://old.example.com/blog and https://old.example.com' ) );
	}

	public function test_invalid_regex_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Replacer( array( array( '(unclosed', 'x' ) ), array( 'regex' => true ) );
	}

	public function test_several_pairs_do_not_chain(): void {
		$r = new Replacer( array( array( 'a', 'b' ), array( 'b', 'c' ) ) );
		$this->assertSame( 'bc', $r->replace( 'ab' ) );
	}

	public function test_empty_search_is_ignored(): void {
		$r = new Replacer( array( array( '', 'x' ) ) );
		$this->assertFalse( $r->is_active() );
		$this->assertSame( 'abc', $r->replace( 'abc' ) );
	}

	public function test_serialized_walker_rejects_trailing_garbage(): void {
		$this->assertNull( Serialized::transform( 's:1:"a";junk', static function ( $s ) {
			return $s;
		} ) );
		$this->assertTrue( Serialized::is_valid( serialize( array( 1, 'two', array( 3.5, null, false ) ) ) ) );
	}

	public function test_non_string_input_passes_through(): void {
		$r = $this->replacer();
		$this->assertSame( '', $r->replace( '' ) );
	}
}
