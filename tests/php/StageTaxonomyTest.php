<?php
/**
 * Stage storage in the internal masthead_stage taxonomy.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Tests;

use Masthead\Meta;
use Masthead\Setup;
use Masthead\StageTaxonomy;
use Masthead\WorkflowEditor;
use Masthead\Workflows;
use WP_REST_Request;

final class StageTaxonomyTest extends TestCase {

	public function test_taxonomy_is_internal(): void {
		$tax = get_taxonomy( StageTaxonomy::TAXONOMY );
		$this->assertNotFalse( $tax );
		$this->assertFalse( $tax->public );
		$this->assertFalse( $tax->show_ui );
		$this->assertFalse( $tax->show_in_rest );
		$this->assertFalse( $tax->query_var );
		$this->assertFalse( $tax->rewrite );
	}

	public function test_one_term_per_stage(): void {
		$wf  = Workflows::get( 'articles' );
		$map = StageTaxonomy::map( $wf );
		$this->assertSame( array_map( static fn( $s ) => $s->id, $wf->stages ), array_keys( array_intersect_key( $map, array_flip( array_map( static fn( $s ) => $s->id, $wf->stages ) ) ) ) );
		$this->assertSame( 'articles__review', get_term_by( 'term_taxonomy_id', $map['review'], StageTaxonomy::TAXONOMY )->slug );
	}

	public function test_counts_follow_status_and_skip_trash(): void {
		$wf = Workflows::get( 'articles' );
		$a  = $this->post_in( 'draft' );
		$this->post_in( 'draft' );
		$this->assertSame( 2, StageTaxonomy::counts( $wf )['draft'] );

		wp_trash_post( $a->ID );
		$this->assertSame( 1, StageTaxonomy::counts( $wf )['draft'] );
		$this->assertSame( 'draft', Meta::stage( $a->ID ), 'Trashed posts keep their stage.' );

		wp_untrash_post( $a->ID );
		$this->assertSame( 2, StageTaxonomy::counts( $wf )['draft'] );
	}

	public function test_rest_exposes_read_only_stage_field(): void {
		$post = $this->post_in( 'review' );
		$res  = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post->ID ) );
		$this->assertSame( 'review', $res->get_data()['masthead_stage'] );
	}

	public function test_setup_remaps_posts_from_removed_stages(): void {
		$this->as( 'administrator' );
		$ready = $this->post_in( 'ready' );
		Setup::apply(
			[
				'template' => 'news',
				'import'   => false,
			]
		);
		$this->assertSame( 'pitch', Meta::stage( $ready->ID ) );
		$this->assertFalse( get_term_by( 'slug', 'articles__ready', StageTaxonomy::TAXONOMY ) );
	}

	public function test_saving_without_a_stage_moves_posts_and_deletes_its_term(): void {
		$this->as( 'administrator' );
		$post           = $this->post_in( 'ready' );
		$data           = Workflows::get( 'articles' )->to_array();
		$data['stages'] = array_values( array_filter( $data['stages'], static fn( $s ) => 'ready' !== $s['id'] ) );
		WorkflowEditor::save( 'articles', $data );
		$this->assertSame( 'review', Meta::stage( $post->ID ) );
		$this->assertFalse( get_term_by( 'slug', 'articles__ready', StageTaxonomy::TAXONOMY ) );
	}
}
