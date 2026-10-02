<?php
/**
 * Tests for the Personas service class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Services
 */

namespace WordPress\AI\Tests\Integration\Includes\Services;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Personas\Personas as Personas_Experiment;
use WordPress\AI\Services\Personas;

/**
 * Personas test case.
 *
 * @since x.x.x
 */
class Personas_Test extends WP_UnitTestCase {

	/**
	 * Service instance.
	 *
	 * @var \WordPress\AI\Services\Personas
	 */
	private Personas $service;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();
		Personas::reset_cache();
		$this->service = Personas::get_instance();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		Personas::reset_cache();
		remove_all_filters( 'wpai_use_personas' );
		remove_all_filters( 'wpai_personas' );
		remove_all_filters( 'wpai_active_persona' );
		remove_all_filters( 'wpai_max_persona_length' );
		delete_option( Personas::DEFAULT_OPTION );

		if ( post_type_exists( Personas::POST_TYPE ) ) {
			unregister_post_type( Personas::POST_TYPE );
		}

		parent::tearDown();
	}

	/**
	 * Registers the personas post type the way the experiment does.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	private function register_personas_cpt(): void {
		if ( post_type_exists( Personas::POST_TYPE ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral
		register_post_type(
			Personas::POST_TYPE,
			array(
				'public'   => false,
				'supports' => array( 'title', 'editor' ),
			)
		);

		Personas::reset_cache();
	}

	/**
	 * The service option name must match the option the experiment registers.
	 *
	 * The service keeps the name as a literal so it stays usable without the
	 * experiment class; this asserts the two never drift apart.
	 *
	 * @since x.x.x
	 */
	public function test_default_option_name_matches_experiment_field(): void {
		$this->assertSame(
			Personas_Experiment::get_field_option_name( 'default_persona' ),
			Personas::DEFAULT_OPTION,
			'The service option name should match the experiment settings field'
		);
	}

	/**
	 * The built-in personas should always be registered.
	 *
	 * @since x.x.x
	 */
	public function test_get_personas_returns_built_ins(): void {
		$personas = $this->service->get_personas();

		$this->assertArrayHasKey( 'professional', $personas );
		$this->assertArrayHasKey( 'label', $personas['professional'] );
		$this->assertNotEmpty( $personas['professional']['voice'] );
	}

	/**
	 * Personas defined in the post type should join the registry.
	 *
	 * @since x.x.x
	 */
	public function test_get_personas_includes_persona_posts(): void {
		$this->register_personas_cpt();

		self::factory()->post->create(
			array(
				'post_type'    => Personas::POST_TYPE,
				'post_title'   => 'House Voice',
				'post_name'    => 'house-voice',
				'post_content' => 'Direct and unfussy.',
				'post_status'  => 'publish',
			)
		);
		Personas::reset_cache();

		$personas = $this->service->get_personas();

		$this->assertArrayHasKey( 'house-voice', $personas );
		$this->assertSame( 'House Voice', $personas['house-voice']['label'] );
		$this->assertSame( 'Direct and unfussy.', $personas['house-voice']['voice'] );
	}

	/**
	 * A persona post should replace a built-in persona of the same slug.
	 *
	 * @since x.x.x
	 */
	public function test_persona_post_overrides_built_in_of_same_id(): void {
		$this->register_personas_cpt();

		self::factory()->post->create(
			array(
				'post_type'    => Personas::POST_TYPE,
				'post_title'   => 'Our Professional Voice',
				'post_name'    => 'professional',
				'post_content' => 'Formal, but never stiff.',
				'post_status'  => 'publish',
			)
		);
		Personas::reset_cache();

		$personas = $this->service->get_personas();

		$this->assertSame( 'Our Professional Voice', $personas['professional']['label'] );
		$this->assertSame( 'Formal, but never stiff.', $personas['professional']['voice'] );
	}

	/**
	 * Markup and shortcodes should never reach a prompt.
	 *
	 * @since x.x.x
	 */
	public function test_persona_text_is_reduced_to_plain_text(): void {
		$this->register_personas_cpt();

		self::factory()->post->create(
			array(
				'post_type'    => Personas::POST_TYPE,
				'post_title'   => 'Blocky',
				'post_name'    => 'blocky',
				'post_content' => "<!-- wp:paragraph -->\n<p>Warm  and\nclear.</p>\n<!-- /wp:paragraph -->",
				'post_status'  => 'publish',
			)
		);
		Personas::reset_cache();

		$personas = $this->service->get_personas();

		$this->assertSame( 'Warm and clear.', $personas['blocky']['voice'] );
	}

	/**
	 * The filter should allow adding and removing personas.
	 *
	 * @since x.x.x
	 */
	public function test_personas_filter_can_add_and_remove(): void {
		add_filter(
			'wpai_personas',
			static function ( $personas ) {
				unset( $personas['playful'] );

				$personas['brand'] = array(
					'label' => 'Brand',
					'voice' => 'Bold and concise.',
				);

				return $personas;
			}
		);
		Personas::reset_cache();

		$personas = $this->service->get_personas();

		$this->assertArrayNotHasKey( 'playful', $personas );
		$this->assertArrayHasKey( 'brand', $personas );
	}

	/**
	 * Personas without a label cannot be selected, so they are dropped.
	 *
	 * @since x.x.x
	 */
	public function test_personas_without_a_label_are_dropped(): void {
		add_filter(
			'wpai_personas',
			static function ( $personas ) {
				$personas['unlabelled'] = array( 'voice' => 'Bold.' );

				return $personas;
			}
		);
		Personas::reset_cache();

		$this->assertArrayNotHasKey( 'unlabelled', $this->service->get_personas() );
	}

	/**
	 * The per-post override should win over the site-wide default.
	 *
	 * @since x.x.x
	 */
	public function test_active_persona_prefers_post_meta_over_default(): void {
		update_option( Personas::DEFAULT_OPTION, 'professional' );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Personas::META_KEY, 'technical' );

		$this->assertSame( 'technical', $this->service->get_active_persona_id( $post_id ) );
		$this->assertSame( 'professional', $this->service->get_active_persona_id( null ) );
	}

	/**
	 * A post set to the reserved "none" value should opt out of the default.
	 *
	 * @since x.x.x
	 */
	public function test_post_can_opt_out_of_the_default_persona(): void {
		$this->register_personas_cpt();
		update_option( Personas::DEFAULT_OPTION, 'professional' );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Personas::META_KEY, Personas::NONE );

		$this->assertSame( '', $this->service->format_for_prompt( $post_id ) );
	}

	/**
	 * The formatted persona should be XML-tagged and contain each field.
	 *
	 * @since x.x.x
	 */
	public function test_format_for_prompt_returns_tagged_persona(): void {
		$this->register_personas_cpt();
		update_option( Personas::DEFAULT_OPTION, 'technical' );

		$formatted = $this->service->format_for_prompt();

		$this->assertStringContainsString( '<persona>', $formatted );
		$this->assertStringContainsString( '<name>', $formatted );
		$this->assertStringContainsString( '<role>', $formatted );
		$this->assertStringContainsString( '<voice>', $formatted );
		$this->assertStringContainsString( '<audience>', $formatted );
		$this->assertStringContainsString( '</persona>', $formatted );
	}

	/**
	 * Nothing should be injected while the experiment is disabled.
	 *
	 * @since x.x.x
	 */
	public function test_format_for_prompt_returns_empty_when_unavailable(): void {
		update_option( Personas::DEFAULT_OPTION, 'technical' );

		$this->assertFalse( $this->service->is_available() );
		$this->assertSame( '', $this->service->format_for_prompt() );
	}

	/**
	 * The kill-switch filter should suppress injection.
	 *
	 * @since x.x.x
	 */
	public function test_use_personas_filter_suppresses_injection(): void {
		$this->register_personas_cpt();
		update_option( Personas::DEFAULT_OPTION, 'technical' );

		add_filter( 'wpai_use_personas', '__return_false' );

		$this->assertSame( '', $this->service->format_for_prompt() );
	}

	/**
	 * An unknown persona ID should inject nothing rather than fail.
	 *
	 * @since x.x.x
	 */
	public function test_format_for_prompt_returns_empty_for_unknown_persona(): void {
		$this->register_personas_cpt();
		update_option( Personas::DEFAULT_OPTION, 'does-not-exist' );

		$this->assertSame( '', $this->service->format_for_prompt() );
	}

	/**
	 * Persona fields should be truncated to the configured maximum length.
	 *
	 * @since x.x.x
	 */
	public function test_persona_fields_are_truncated(): void {
		$this->register_personas_cpt();

		add_filter(
			'wpai_personas',
			static function ( $personas ) {
				$personas['long'] = array(
					'label' => 'Long',
					'voice' => str_repeat( 'a', 50 ),
				);

				return $personas;
			}
		);
		add_filter(
			'wpai_max_persona_length',
			static function () {
				return 10;
			}
		);
		Personas::reset_cache();
		update_option( Personas::DEFAULT_OPTION, 'long' );

		$this->assertStringContainsString( '<voice>' . str_repeat( 'a', 10 ) . '</voice>', $this->service->format_for_prompt() );
	}

	/**
	 * The active persona filter should be able to override the resolution.
	 *
	 * @since x.x.x
	 */
	public function test_active_persona_filter_overrides_resolution(): void {
		$this->register_personas_cpt();
		update_option( Personas::DEFAULT_OPTION, 'professional' );

		add_filter(
			'wpai_active_persona',
			static function () {
				return 'journalistic';
			}
		);

		$this->assertStringContainsString( 'Journalistic', $this->service->format_for_prompt() );
	}

	/**
	 * Registers the personas post type exactly as the experiment does.
	 *
	 * Capability behavior depends on the real registration arguments, so these
	 * tests must not use the simplified helper above.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Post_Type The registered post type object.
	 */
	private function register_personas_cpt_from_experiment(): \WP_Post_Type {
		if ( post_type_exists( Personas::POST_TYPE ) ) {
			unregister_post_type( Personas::POST_TYPE );
		}

		( new Personas_Experiment() )->register_post_type();
		Personas::reset_cache();

		$post_type = get_post_type_object( Personas::POST_TYPE );

		$this->assertInstanceOf( \WP_Post_Type::class, $post_type );

		return $post_type;
	}

	/**
	 * Authors should not be able to define personas.
	 *
	 * A persona's text is injected into the system instruction of every
	 * generation that uses it, so defining one is site-level configuration
	 * rather than authoring.
	 *
	 * @since x.x.x
	 */
	public function test_authors_cannot_manage_personas(): void {
		$post_type = $this->register_personas_cpt_from_experiment();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertFalse(
			current_user_can( $post_type->cap->create_posts ),
			'An author should not be able to create personas'
		);
		$this->assertFalse(
			current_user_can( $post_type->cap->edit_posts ),
			'An author should not reach the personas list table'
		);

		$persona_id = self::factory()->post->create( array( 'post_type' => Personas::POST_TYPE ) );

		$this->assertFalse(
			current_user_can( 'edit_post', $persona_id ),
			'An author should not be able to edit a persona'
		);
		$this->assertFalse(
			current_user_can( 'delete_post', $persona_id ),
			'An author should not be able to delete a persona'
		);
	}

	/**
	 * Editors and administrators should be able to define personas.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_roles_that_manage_personas
	 *
	 * @param string $role The role to check.
	 */
	public function test_editors_and_admins_can_manage_personas( string $role ): void {
		$post_type = $this->register_personas_cpt_from_experiment();

		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		$this->assertTrue(
			current_user_can( $post_type->cap->create_posts ),
			sprintf( 'A %s should be able to create personas', $role )
		);

		$persona_id = self::factory()->post->create( array( 'post_type' => Personas::POST_TYPE ) );

		$this->assertTrue(
			current_user_can( 'edit_post', $persona_id ),
			sprintf( 'A %s should be able to edit a persona', $role )
		);
	}

	/**
	 * Data provider for roles that may manage personas.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string}> Role names.
	 */
	public function data_roles_that_manage_personas(): array {
		return array(
			'editor'        => array( 'editor' ),
			'administrator' => array( 'administrator' ),
		);
	}

	/**
	 * Selecting a persona for a post should stay on `edit_post`.
	 *
	 * Authors cannot define personas, but they must still be able to choose one
	 * for a post they can edit.
	 *
	 * @since x.x.x
	 */
	public function test_authors_can_select_a_persona_for_their_own_post(): void {
		$this->register_personas_cpt_from_experiment();

		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );

		$post_id = self::factory()->post->create( array( 'post_author' => $author_id ) );

		$this->assertTrue(
			current_user_can( 'edit_post', $post_id ),
			'An author should be able to edit their own post'
		);
		$this->assertNotFalse(
			update_post_meta( $post_id, Personas::META_KEY, 'technical' ),
			'An author should be able to set the persona on their own post'
		);
		$this->assertSame( 'technical', $this->service->get_active_persona_id( $post_id ) );
	}

	/**
	 * The capability map should be overridable for sites with other needs.
	 *
	 * @since x.x.x
	 */
	public function test_post_type_args_filter_can_change_the_capability(): void {
		add_filter(
			'wpai_persona_post_type_args',
			static function ( array $args ): array {
				$args['capabilities'] = array_fill_keys( array_keys( $args['capabilities'] ), 'manage_options' );

				return $args;
			}
		);

		$post_type = $this->register_personas_cpt_from_experiment();

		remove_all_filters( 'wpai_persona_post_type_args' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertFalse(
			current_user_can( $post_type->cap->create_posts ),
			'An editor should not create personas once the filter tightens the capability'
		);
	}

	/**
	 * The innermost captured ability context should resolve the persona.
	 *
	 * @since x.x.x
	 */
	public function test_context_stack_resolves_the_innermost_post(): void {
		$outer = self::factory()->post->create();
		$inner = self::factory()->post->create();
		update_post_meta( $outer, Personas::META_KEY, 'professional' );
		update_post_meta( $inner, Personas::META_KEY, 'technical' );

		Personas::push_context( $outer );
		$this->assertSame( 'professional', $this->service->get_active_persona_id() );

		Personas::push_context( $inner );
		$this->assertSame( 'technical', $this->service->get_active_persona_id() );

		Personas::pop_context();
		$this->assertSame( 'professional', $this->service->get_active_persona_id() );

		Personas::pop_context();
		$this->assertSame( '', $this->service->get_active_persona_id() );
	}
}
