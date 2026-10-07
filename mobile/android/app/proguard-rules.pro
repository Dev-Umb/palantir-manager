# Preserve the native secure-storage boundary for instrumentation against the release APK.
-keep class cn.xinyuanchang.palantir_mobile.SessionStore { *; }

# Keep the updater validation boundary callable from isolated release instrumentation.
-keep class cn.xinyuanchang.palantir_mobile.AppUpdater { *; }
