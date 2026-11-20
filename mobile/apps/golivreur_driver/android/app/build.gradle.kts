import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
}

val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    FileInputStream(keystorePropertiesFile).use { keystoreProperties.load(it) }
}

android {
    namespace = "ci.golivreur.driver"
    compileSdk = 36
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "ci.golivreur.driver"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    if (keystorePropertiesFile.exists()) {
        signingConfigs {
            create("release") {
                keyAlias = requireNotNull(keystoreProperties.getProperty("keyAlias")?.takeIf { it.isNotBlank() }) {
                    "Propriété 'keyAlias' manquante dans android/key.properties"
                }
                keyPassword = requireNotNull(keystoreProperties.getProperty("keyPassword")?.takeIf { it.isNotBlank() }) {
                    "Propriété 'keyPassword' manquante dans android/key.properties"
                }
                storeFile = file(
                    requireNotNull(keystoreProperties.getProperty("storeFile")?.takeIf { it.isNotBlank() }) {
                        "Propriété 'storeFile' manquante dans android/key.properties"
                    }
                )
                storePassword = requireNotNull(keystoreProperties.getProperty("storePassword")?.takeIf { it.isNotBlank() }) {
                    "Propriété 'storePassword' manquante dans android/key.properties"
                }
            }
        }
    }

    buildTypes {
        release {
            if (keystorePropertiesFile.exists()) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
