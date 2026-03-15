<?php
/**
 * Link frontend SDK from framework to frontend/src/sdk
 *
 * This script:
 * 1. Checks if we're in a demo project (framework available via path repository)
 * 2. If demo: SKIP symlink creation (framework is mounted directly in Docker at /hilos/framework)
 * 3. If standalone: creates symlink to vendor/anlide/hilos/frontend
 *
 * This script is called automatically after composer install/update
 */

$autoloadPaths = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

use Hilos\Constants\EnvConstants;

// Detect if we're running in Docker container
$isDocker = file_exists('/.dockerenv') || getenv(EnvConstants::DOCKER->name) === 'true';

// Find project root and current working directory
$currentDir = getcwd();
$frontendDirs = [];

// Check if we're in a demo project (running from /app in Docker or demo/chat locally)
if ($isDocker && $currentDir === '/app') {
    // Running in Docker container, /app is the demo project root
    $demoFrontend = '/app/frontend';
    if (is_dir($demoFrontend)) {
        $frontendDirs[] = $demoFrontend;
    }
} else {
    // Running locally - find frontend directories
    $scriptDir = __DIR__;
    $frameworkRoot = dirname($scriptDir);
    $projectRoot = dirname($frameworkRoot);
    
    // Check if we're in a demo project
    if (strpos($currentDir, '/demo/') !== false || strpos($currentDir, '\\demo\\') !== false) {
        // We're inside a demo project
        $demoProjectRoot = $currentDir;
        while ($demoProjectRoot !== '/' && $demoProjectRoot !== '' && $demoProjectRoot !== 'C:\\' && $demoProjectRoot !== 'c:\\') {
            if (basename($demoProjectRoot) === 'demo' || (file_exists($demoProjectRoot . '/composer.json') && basename(dirname($demoProjectRoot)) === 'demo')) {
                break;
            }
            $demoProjectRoot = dirname($demoProjectRoot);
        }
        
        $demoFrontend = $demoProjectRoot . '/frontend';
        if (is_dir($demoFrontend)) {
            $frontendDirs[] = $demoFrontend;
        }
    } else {
        // Check all demo projects
        $demoFrontendPath = $projectRoot . '/demo/*/frontend';
        $demoMatches = glob($demoFrontendPath);
        if ($demoMatches !== false) {
            foreach ($demoMatches as $demoPath) {
                $frontendDirs[] = $demoPath;
            }
        }
    }
}

// Process each frontend directory
foreach ($frontendDirs as $frontendDir) {
    if (!is_dir($frontendDir)) {
        continue;
    }
    
    $sdkTarget = $frontendDir . '/src/sdk';
    
    // Determine SDK source path
    $sdkSource = null;
    $isDemo = false;
    
    // In Docker: check /hilos/framework/frontend first (mounted volume)
    if ($isDocker && is_dir('/hilos/framework/frontend')) {
        $sdkSource = '/hilos/framework/frontend';
        $isDemo = true;
    } else {
        // Check if framework is available via path repository (demo project)
        $frameworkPath = dirname(dirname(dirname($frontendDir))) . '/framework/frontend';
        if (is_dir($frameworkPath)) {
            $sdkSource = $frameworkPath;
            $isDemo = true;
        } else {
            // Check if framework is installed via composer (standalone project)
            $vendorSdkPath = dirname($frontendDir) . '/vendor/anlide/hilos/frontend';
            if (is_dir($vendorSdkPath)) {
                $sdkSource = $vendorSdkPath;
                $isDemo = false;
            } else {
                // Try alternative path (if frontend is in project root)
                $vendorSdkPath = dirname(dirname($frontendDir)) . '/vendor/anlide/hilos/frontend';
                if (is_dir($vendorSdkPath)) {
                    $sdkSource = $vendorSdkPath;
                    $isDemo = false;
                }
            }
        }
    }
    
    if ($sdkSource === null || !is_dir($sdkSource)) {
        echo "⚠️  Framework SDK not found for: $frontendDir\n";
        echo "   Expected locations:\n";
        if ($isDocker) {
            echo "   - Docker: /hilos/framework/frontend\n";
        } else {
            $frameworkPath = dirname(dirname(dirname($frontendDir))) . '/framework/frontend';
            echo "   - Demo: $frameworkPath\n";
        }
        echo "   - Standalone: " . dirname($frontendDir) . "/vendor/anlide/hilos/frontend\n";
        continue;
    }
    
    $projectType = $isDemo ? 'demo' : 'standalone';
    echo "📦 [$projectType] Processing SDK for: " . basename(dirname($frontendDir)) . "\n";
    
    // For demo projects: framework is mounted directly in Docker, no symlink needed
    if ($isDemo) {
        echo "   ℹ️  Demo project: Framework is mounted at /hilos/framework in Docker\n";
        echo "   ℹ️  No symlink needed - Vite will use /hilos/framework/frontend/src directly\n";
        
        // Remove existing symlink if it exists (cleanup)
        if (is_link($sdkTarget)) {
            unlink($sdkTarget);
            echo "   🗑️  Removed old symlink (no longer needed)\n";
        } elseif (is_dir($sdkTarget)) {
            $files = scandir($sdkTarget);
            if (count($files) <= 2) { // Only . and ..
                rmdir($sdkTarget);
                echo "   🗑️  Removed empty directory\n";
            }
        }
        continue;
    }
    
    // For standalone projects: create symlink to vendor package
    // Create src directory if it doesn't exist
    $srcDir = $frontendDir . '/src';
    if (!is_dir($srcDir)) {
        mkdir($srcDir, 0755, true);
    }
    
    // Remove existing symlink or directory
    if (is_link($sdkTarget)) {
        $existingLink = readlink($sdkTarget);
        if ($existingLink === $sdkSource || realpath($existingLink) === realpath($sdkSource)) {
            echo "   ✅ SDK already linked correctly\n";
            continue;
        }
        unlink($sdkTarget);
        echo "   🗑️  Removed existing symlink\n";
    } elseif (is_dir($sdkTarget)) {
        // If it's a directory (not symlink), check if it's empty or warn
        $files = scandir($sdkTarget);
        if (count($files) <= 2) { // Only . and ..
            rmdir($sdkTarget);
            echo "   🗑️  Removed empty directory\n";
        } else {
            echo "   ⚠️  SDK target exists as directory with files\n";
            echo "      Please remove $sdkTarget manually if you want to link SDK\n";
            continue;
        }
    }
    
    // Create symlink for standalone projects
    if (symlink($sdkSource, $sdkTarget)) {
        echo "   ✅ SDK linked: $sdkTarget -> $sdkSource\n";
    } else {
        $error = error_get_last();
        echo "   ❌ Failed to create symlink: " . ($error['message'] ?? 'Unknown error') . "\n";
    }
}

echo "✨ Frontend SDK linking completed\n";
