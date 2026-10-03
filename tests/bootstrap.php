<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$tests_dir = rtrim( (string) getenv( 'WP_TESTS_DIR' ), "/\\" );
$plugin_file = (string) getenv( 'CB_PLUGIN_FILE' );

if ( '' === $tests_dir || ! is_file( $tests_dir . '/includes/functions.php' ) ) {
    throw new RuntimeException( 'WP_TESTS_DIR must point to the pinned WordPress PHPUnit library.' );
}

if ( '' === $plugin_file ) {
    $plugin_file = $root . '/core-blueprint.php';
}

if ( ! is_file( $plugin_file ) ) {
    throw new RuntimeException( 'CB_PLUGIN_FILE does not point to a readable Core Blueprint plugin entrypoint.' );
}

require_once $root . '/vendor/autoload.php';
require_once $root . '/tests/fixtures/EnvironmentTypeTestShim.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
    define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $root . '/vendor/yoast/phpunit-polyfills' );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $root . '/tests/wp-tests-config.php' );
if ( false === getenv( 'WP_PHPUNIT__TABLE_PREFIX' ) ) {
    putenv( 'WP_PHPUNIT__TABLE_PREFIX=cbtests_' );
}

require_once $tests_dir . '/includes/functions.php';

// CI must never attempt external mail delivery. Base's alert routing and
// wp_mail() call path still execute; WordPress is short-circuited only at the
// final transport boundary. Dedicated Mail tests may override this later.
tests_add_filter(
    'pre_wp_mail',
    static function () {
        return true;
    }
);

tests_add_filter(
    'muplugins_loaded',
    static function () use ( $plugin_file ): void {
        require_once $plugin_file;

        // Register deterministic test-only Abilities through WordPress' real
        // lifecycle. They let the integration suite exercise the same execute()
        // path used by PHP, REST and MCP consumers without installing an AI
        // provider or teaching production code about the test harness.
        add_action(
            'wp_abilities_api_categories_init',
            static function (): void {
                wp_register_ability_category(
                    'core-blueprint-test',
                    [
                        'label'       => 'Core Blueprint test',
                        'description' => 'Integration-test Abilities for Core Blueprint governance.',
                    ]
                );
            }
        );
        add_action(
            'wp_abilities_api_init',
            static function (): void {
                $common = [
                    'category'      => 'core-blueprint-test',
                    'input_schema'  => [
                        'type'       => 'object',
                        'properties' => [
                            'secret' => [ 'type' => 'string' ],
                        ],
                    ],
                    'output_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'ok' => [ 'type' => 'boolean' ],
                        ],
                    ],
                    'meta'          => [ 'public' => false ],
                ];

                wp_register_ability(
                    'core-blueprint-test/success',
                    array_merge(
                        $common,
                        [
                            'label'               => 'Governance success fixture',
                            'description'         => 'Returns a successful bounded result.',
                            'execute_callback'    => static fn( array $input ): array => [ 'ok' => true ],
                            'permission_callback' => '__return_true',
                        ]
                    )
                );

                wp_register_ability(
                    'core-blueprint-test/denied',
                    array_merge(
                        $common,
                        [
                            'label'               => 'Governance denied fixture',
                            'description'         => 'Always fails its permission check.',
                            'execute_callback'    => static fn( array $input ): array => [ 'ok' => true ],
                            'permission_callback' => '__return_false',
                        ]
                    )
                );

                wp_register_ability(
                    'core-blueprint-test/failed',
                    array_merge(
                        $common,
                        [
                            'label'               => 'Governance failure fixture',
                            'description'         => 'Returns a deterministic WP_Error.',
                            'execute_callback'    => static fn( array $input ): WP_Error => new WP_Error( 'cb_ai_fixture_failed', 'Fixture failure.' ),
                            'permission_callback' => '__return_true',
                        ]
                    )
                );
            }
        );

        // The WordPress test database starts without plugin activation state.
        // Establish Base through its real activation lifecycle before its normal
        // plugins_loaded migrations and policy checks execute. This keeps smoke
        // tests representative of an installed/activated site and avoids
        // manufacturing schema state inside the test harness.
        add_action(
            'plugins_loaded',
            static function (): void {
                \CoreBlueprint\Core\Core::activate();
            },
            2
        );
    }
);

require_once $tests_dir . '/includes/bootstrap.php';