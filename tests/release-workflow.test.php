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
        "=== Plugin ===\nStable tag: dev\n\n== Upgrade Notice ==\n\n= 1.1.1 =\n\n- Upgrade before the cutoff.\n\n== Changelog ==\n\n= 1.1.1 =\n\n- Fixed release packaging.\n\n= 1.1.0 =\n\n- Previous release.\n"
    );

    prepareRelease($temporaryRoot, '1.1.1');

    $plugin = file_get_contents($temporaryRoot . '/woocommerce-payment-gateway-app.php');
    $readme = file_get_contents($temporaryRoot . '/README.md');
    $releaseNotes = file_get_contents($temporaryRoot . '/RELEASE.md');

    releaseAssertContains(' * Version: 1.1.1', $plugin, 'The packaged PHP header must contain the tag version.');
    releaseAssertContains('Stable tag: 1.1.1', $readme, 'The packaged README stable tag must contain the tag version.');
    releaseAssertContains('= 1.1.1 =', $releaseNotes, 'Release notes must include the matching changelog heading.');
    releaseAssertContains('- Fixed release packaging.', $releaseNotes, 'Release notes must include the matching changelog items.');
    releaseAssertNotContains('- Upgrade before the cutoff.', $releaseNotes, 'Release notes must not use the duplicate Upgrade Notice heading.');
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

    file_put_contents(
        $temporaryRoot . '/README.md',
        "=== Plugin ===\nStable tag: 1.1.1\n\n== Changelog ==\n\n= 1.1.1 =\n\n- First entry.\n\n= 1.1.1 =\n\n- Duplicate entry.\n"
    );
    $duplicateChangelogRejected = false;
    try {
        prepareRelease($temporaryRoot, '1.1.1');
    } catch (RuntimeException $exception) {
        $duplicateChangelogRejected = str_contains($exception->getMessage(), 'exactly one changelog entry');
    }
    releaseAssertSame(true, $duplicateChangelogRejected, 'A duplicate version entry inside Changelog must be rejected.');

    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/phpreleaser.yml');
    releaseAssertContains('repository_dispatch:', $workflow, 'Publication must use the default-branch repository dispatch event.');
    releaseAssertContains('types: [plugin-release-approved]', $workflow, 'Publication must require the approved release event type.');
    releaseAssertContains('github.event.client_payload.source_sha', $workflow, 'Publication must select an exact reviewed source commit.');
    releaseAssertContains('PLUGIN_RELEASE_VERSION: ${{ github.event.client_payload.version', $workflow, 'Publication must use the selected semantic version.');
    releaseAssertNotContains('workflow_dispatch:', $workflow, 'Privileged publication must not be ref-selectable.');
    releaseAssertNotContains('pull_request:', $workflow, 'Privileged publication must not execute from pull request source.');
    releaseAssertContains('refs/heads/main', $workflow, 'Publication must execute from the protected default branch.');
    releaseAssertContains('github.ref_protected', $workflow, 'Publication must require GitHub to identify the workflow ref as protected.');
    releaseAssertContains('.github/release-policy.json', $workflow, 'Publication must use the trusted repository release manifest.');
    releaseAssertContains("permissions:\n  contents: read", $workflow, 'The workflow default must be read-only.');
    releaseAssertContains('needs: validate', $workflow, 'Publication must depend on successful validation.');
    releaseAssertContains("permissions:\n      contents: write", $workflow, 'Only publication may write repository contents.');
    releaseAssertContains('php tests/ipn-v2-receiver.test.php', $workflow, 'The workflow must verify the IPN v2 receiver contract before packaging.');
    releaseAssertNotContains('payment-gateway-release-orchestrator/', $workflow, 'A public plugin workflow must not import the private release orchestrator.');
    releaseAssertContains('scripts/validate-release-policy.mjs', $workflow, 'Publication must run the repository-local SemVer policy.');
    releaseAssertContains('.releases[$version].artifactName', $workflow, 'The archive filename must come from the trusted release manifest.');
    releaseAssertContains('sha256sum', $workflow, 'Publication must create an exact SHA-256 checksum asset.');
    releaseAssertContains('scripts/publish-release.mjs', $workflow, 'Publication must verify a resumable draft before making the release visible.');
    releaseAssertContains('persist-credentials: false', $workflow, 'Publication checkouts must not retain write credentials.');
    releaseAssertContains('release-control/$PREPARE_SCRIPT', $workflow, 'Packaging must use the trusted control-tree packager.');
    $prWorkflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/validate-release-controls.yml');
    releaseAssertContains('pull_request:', $prWorkflow, 'Pull requests must retain read-only release-control validation.');
    releaseAssertContains("permissions:\n  contents: read", $prWorkflow, 'Pull request validation must default to contents read.');
    releaseAssertNotContains('contents: write', $prWorkflow, 'Pull request validation must never publish.');
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
