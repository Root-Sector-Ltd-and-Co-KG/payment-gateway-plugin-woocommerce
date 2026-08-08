<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/prepare-release.php';

function releaseAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function releaseAssertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, $message . "\nMissing: " . $needle . "\n");
        exit(1);
    }
}

function releaseAssertNotContains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        fwrite(STDERR, $message . "\nUnexpected: " . $needle . "\n");
        exit(1);
    }
}

$temporaryRoot = sys_get_temp_dir() . '/woocommerce-payment-gateway-app-release-' . bin2hex(random_bytes(6));
mkdir($temporaryRoot, 0777, true);

try {
    file_put_contents(
        $temporaryRoot . '/woocommerce-payment-gateway-app.php',
        "<?php\n/**\n * Version: dev\n */\n"
    );
    file_put_contents(
        $temporaryRoot . '/README.md',
        "=== Plugin ===\nStable tag: dev\n\n== Changelog ==\n\n= 1.1.1 =\n\n- Fixed release packaging.\n\n= 1.1.0 =\n\n- Previous release.\n"
    );

    prepareRelease($temporaryRoot, '1.1.1');

    $plugin = file_get_contents($temporaryRoot . '/woocommerce-payment-gateway-app.php');
    $readme = file_get_contents($temporaryRoot . '/README.md');
    $releaseNotes = file_get_contents($temporaryRoot . '/RELEASE.md');

    releaseAssertContains(' * Version: 1.1.1', $plugin, 'The packaged PHP header must contain the tag version.');
    releaseAssertContains('Stable tag: 1.1.1', $readme, 'The packaged README stable tag must contain the tag version.');
    releaseAssertContains('= 1.1.1 =', $releaseNotes, 'Release notes must include the matching changelog heading.');
    releaseAssertContains('- Fixed release packaging.', $releaseNotes, 'Release notes must include the matching changelog items.');
    releaseAssertNotContains('= 1.1.0 =', $releaseNotes, 'Release notes must stop before the previous version.');

    $invalidVersionRejected = false;
    try {
        prepareRelease($temporaryRoot, 'release/latest');
    } catch (InvalidArgumentException $exception) {
        $invalidVersionRejected = true;
    }
    releaseAssertSame(true, $invalidVersionRejected, 'Non-semantic release tags must be rejected.');

    $missingChangelogRejected = false;
    try {
        prepareRelease($temporaryRoot, '1.1.2');
    } catch (RuntimeException $exception) {
        $missingChangelogRejected = str_contains($exception->getMessage(), 'changelog entry');
    }
    releaseAssertSame(true, $missingChangelogRejected, 'A release without a matching changelog entry must be rejected.');

    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/phpreleaser.yml');
    releaseAssertContains('- "**"', $workflow, 'The workflow must validate every tag name, including tags containing slashes.');
    releaseAssertContains('PLUGIN_RELEASE_VERSION: ${{ github.ref_name }}', $workflow, 'The workflow must derive the release version from the Git tag.');
    releaseAssertContains('php tests/ipn-v2-receiver.test.php', $workflow, 'The workflow must verify the IPN v2 receiver contract before packaging.');
    releaseAssertContains('validate_release_policy:', $workflow, 'Tag publication must run the central plugin release validator.');
    releaseAssertContains('payment-gateway-release-orchestrator/.github/workflows/validate-plugin-release.yml@7464cd4214900f1c2d3c6ad33716099b227c34b7', $workflow, 'The reusable release validator must be pinned to the reviewed immutable revision.');
    releaseAssertContains('policy_ref: 7464cd4214900f1c2d3c6ad33716099b227c34b7', $workflow, 'The checked-out release policy must match the reusable workflow revision.');
    releaseAssertContains('needs: validate_release_policy', $workflow, 'Release publication must depend on successful central policy validation.');
    releaseAssertContains('php scripts/prepare-release.php "$PLUGIN_RELEASE_VERSION"', $workflow, 'The workflow must prepare versioned release files.');
    releaseAssertContains('filename: woocommerce-payment-gateway-app_v${{ env.PLUGIN_RELEASE_VERSION }}.zip', $workflow, 'The archive filename must include the release version.');
    releaseAssertContains('woocommerce-payment-gateway-app/scripts/* woocommerce-payment-gateway-app/tests/*', $workflow, 'Development scripts and tests must be excluded from the archive.');
    releaseAssertContains('bodyFile: woocommerce-payment-gateway-app/RELEASE.md', $workflow, 'The GitHub release must use the matching changelog section.');

    $hposWorkflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/hpos-integration.yml');
    releaseAssertContains('npm ci', $hposWorkflow, 'The HPOS integration workflow must install its locked WordPress environment.');
    releaseAssertContains('npm run wp-env -- start', $hposWorkflow, 'The HPOS integration workflow must start a genuine WordPress environment.');
    releaseAssertContains('woocommerce_custom_orders_table_enabled yes', $hposWorkflow, 'The integration workflow must enable HPOS before testing.');
    releaseAssertContains('woocommerce_custom_orders_table_data_sync_enabled no', $hposWorkflow, 'The integration workflow must test authoritative HPOS storage without post-meta synchronization.');
    releaseAssertContains('wp eval-file wp-content/plugins/payment-gateway-plugin-woocommerce/tests/hpos-integration.php', $hposWorkflow, 'The workflow must execute the HPOS persistence integration inside WordPress.');

    $wpEnvironment = file_get_contents(dirname(__DIR__) . '/.wp-env.json');
    releaseAssertContains('WordPress/WordPress#7.0.3', $wpEnvironment, 'The integration fixture must pin WordPress.');
    releaseAssertContains('woocommerce/releases/download/11.0.0/woocommerce.zip', $wpEnvironment, 'The integration fixture must pin the real WooCommerce plugin artifact.');

    $hposIntegration = file_get_contents(dirname(__DIR__) . '/tests/hpos-integration.php');
    releaseAssertContains('custom_orders_table_usage_is_enabled()', $hposIntegration, 'The integration must verify that HPOS is active.');
    releaseAssertContains('OrdersTableDataStore', $hposIntegration, 'The integration must verify that the WooCommerce HPOS order data store is in use.');
    releaseAssertContains('wc_get_order($orderId)', $hposIntegration, 'The integration must reload claims through WooCommerce order CRUD.');
    releaseAssertContains("\$wpdb->prefix . 'wc_orders_meta'", $hposIntegration, 'The integration must verify persistence in the real HPOS metadata table.');

    $sourceReadme = file_get_contents(dirname(__DIR__) . '/README.md');
    releaseAssertContains('Stable tag: dev', $sourceReadme, 'The source README must use the release-time version placeholder.');
    foreach (array('1.1.1', '1.1.0', '1.0.6', '1.0.5') as $documentedVersion) {
        releaseAssertContains("= {$documentedVersion} =", $sourceReadme, "The changelog must document release {$documentedVersion}.");
    }
} finally {
    $files = array_reverse(glob($temporaryRoot . '/*') ?: array());
    foreach ($files as $file) {
        is_dir($file) ? rmdir($file) : unlink($file);
    }
    rmdir($temporaryRoot);
}

echo "WooCommerce release packaging contract: PASS\n";
