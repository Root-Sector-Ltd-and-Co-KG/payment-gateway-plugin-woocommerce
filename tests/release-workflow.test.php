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
    releaseAssertContains('workflow_dispatch:', $workflow, 'Publication must require a manual pre-tag dispatch.');
    releaseAssertContains('source_sha:', $workflow, 'Publication must select an exact reviewed source commit.');
    releaseAssertContains('PLUGIN_RELEASE_VERSION: ${{ inputs.version', $workflow, 'Publication must use the selected semantic version.');
    releaseAssertContains("permissions:\n  contents: read", $workflow, 'The workflow default must be read-only.');
    releaseAssertContains('needs: validate', $workflow, 'Publication must depend on successful validation.');
    releaseAssertContains("permissions:\n      contents: write", $workflow, 'Only publication may write repository contents.');
    releaseAssertContains('php tests/ipn-v2-receiver.test.php', $workflow, 'The workflow must verify the IPN v2 receiver contract before packaging.');
    releaseAssertNotContains('payment-gateway-release-orchestrator/', $workflow, 'A public plugin workflow must not import the private release orchestrator.');
    releaseAssertContains('scripts/validate-release-policy.mjs', $workflow, 'Publication must run the repository-local SemVer policy.');
    releaseAssertContains('woocommerce-payment-gateway-app_v${{ inputs.version }}.zip', $workflow, 'The archive filename must include the selected version.');
    releaseAssertContains('sha256sum', $workflow, 'Publication must create an exact SHA-256 checksum asset.');
    releaseAssertContains('gh release create', $workflow, 'Publication must create a new immutable release.');
    releaseAssertNotContains('--clobber', $workflow, 'Publication must never replace a release asset.');
    releaseAssertContains('npm ci', $workflow, 'The release validation workflow must install its locked WordPress environment.');
    releaseAssertContains('npm run wp-env -- start', $workflow, 'The release validation workflow must start a genuine WordPress environment.');
    releaseAssertContains('woocommerce_custom_orders_table_enabled yes', $workflow, 'The release validation workflow must enable HPOS before testing.');
    releaseAssertContains('woocommerce_custom_orders_table_data_sync_enabled no', $workflow, 'The release validation workflow must test authoritative HPOS storage without post-meta synchronization.');
    releaseAssertContains('wp eval-file wp-content/plugins/payment-gateway-plugin-woocommerce/tests/hpos-integration.php', $workflow, 'The release validation workflow must execute the HPOS persistence integration inside WordPress.');

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
    foreach (array('1.2.0', '1.1.1', '1.1.0', '1.0.6', '1.0.5') as $documentedVersion) {
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
