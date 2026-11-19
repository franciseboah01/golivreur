package ci.golivreur.business

import android.content.Intent
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    private val deepLinkChannel = "ci.golivreur/deep_links"
    private var methodChannel: MethodChannel? = null
    private var initialDeepLink: String? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        initialDeepLink = intent?.dataString
        methodChannel = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, deepLinkChannel).also { ch ->
            ch.setMethodCallHandler { call, result ->
                if (call.method == "getInitialLink") {
                    result.success(initialDeepLink)
                    initialDeepLink = null
                } else {
                    result.notImplemented()
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        val uri = intent.dataString
        if (!uri.isNullOrEmpty()) {
            methodChannel?.invokeMethod("onDeepLink", uri)
        }
    }
}
