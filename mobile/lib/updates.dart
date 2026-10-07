import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'environment.dart';
import 'ui.dart';

const updateChannel = MethodChannel('palantir/android');
const appPackage = 'cn.xinyuanchang.palantir_mobile';

class AppRelease {
  final int code, size;
  final String name, notes, hash;
  final Uri url;
  AppRelease(this.code, this.name, this.notes, this.hash, this.size, this.url);

  factory AppRelease.parse(Map<String, dynamic> json, Uri origin) {
    final url = Uri.tryParse(json['apkUrl']?.toString() ?? '');
    final code = json['versionCode'];
    final size = json['sizeBytes'];
    final hash = json['sha256']?.toString() ?? '';
    if (code is! int ||
        code <= 0 ||
        size is! int ||
        size <= 0 ||
        size > 150 * 1024 * 1024 ||
        json['packageName'] != appPackage ||
        json['versionName'] is! String ||
        (json['versionName'] as String).isEmpty ||
        json['notes'] is! String ||
        !RegExp(r'^[a-fA-F0-9]{64}$').hasMatch(hash) ||
        url == null ||
        url.scheme != 'https' ||
        url.origin != origin.origin ||
        url.userInfo.isNotEmpty ||
        url.fragment.isNotEmpty ||
        !url.path.endsWith('.apk')) {
      throw const FormatException('更新信息无效，请稍后重试');
    }
    return AppRelease(
      code,
      json['versionName'],
      json['notes'],
      hash.toLowerCase(),
      size,
      url,
    );
  }

  Map<String, dynamic> get arguments => {
    'versionCode': code,
    'apkUrl': url.toString(),
    'sha256': hash,
    'sizeBytes': size,
    'packageName': appPackage,
  };
}

class UpdateService {
  final Uri origin;
  final HttpClient Function() createClient;
  UpdateService({Uri? origin, HttpClient Function()? createClient})
    : origin = origin ?? Uri.parse(productionApiUrl),
      createClient = createClient ?? HttpClient.new;

  Future<Map<String, dynamic>> installed() async => Map<String, dynamic>.from(
    await updateChannel.invokeMapMethod('updateInfo') ?? {},
  );

  Future<AppRelease?> check(int current) async {
    final client = createClient()
      ..connectionTimeout = const Duration(seconds: 10);
    try {
      final request = await client.getUrl(
        origin.resolve('/app-updates/android.json'),
      );
      request.followRedirects = false;
      request.headers.set(HttpHeaders.cacheControlHeader, 'no-cache');
      final response = await request.close().timeout(
        const Duration(seconds: 15),
      );
      if (response.statusCode != 200) {
        throw const HttpException('暂时无法检查更新，请稍后重试');
      }
      final bytes = <int>[];
      await for (final chunk in response.timeout(const Duration(seconds: 15))) {
        bytes.addAll(chunk);
        if (bytes.length > 65536) throw const FormatException('更新信息过大');
      }
      final release = AppRelease.parse(
        Map<String, dynamic>.from(jsonDecode(utf8.decode(bytes))),
        origin,
      );
      return release.code > current ? release : null;
    } finally {
      client.close(force: true);
    }
  }
}

Future<void> checkForUpdate(
  BuildContext context, {
  bool automatic = false,
  UpdateService? service,
}) async {
  final updater = service ?? UpdateService();
  try {
    final info = await updater.installed();
    final release = await updater.check(info['versionCode'] as int);
    if (!context.mounted) return;
    if (release == null) {
      if (!automatic) toast(context, '当前已是最新版本 ${info['versionName']}');
      return;
    }
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (_) => UpdateDialog(release: release),
    );
  } catch (e) {
    if (context.mounted && !automatic) toast(context, '检查更新失败，请稍后重试');
  }
}

class UpdateDialog extends StatefulWidget {
  final AppRelease release;
  const UpdateDialog({super.key, required this.release});
  @override
  State<UpdateDialog> createState() => _UpdateDialogState();
}

class _UpdateDialogState extends State<UpdateDialog> {
  bool downloading = false, installing = false;
  String? path, error;
  double progress = 0;
  Timer? timer;

  @override
  void dispose() {
    timer?.cancel();
    if (downloading) {
      updateChannel
          .invokeMethod<void>('cancelUpdate')
          .catchError((Object _) {});
    }
    super.dispose();
  }

  Future<void> download() async {
    setState(() {
      downloading = true;
      error = null;
      progress = 0;
    });
    timer = Timer.periodic(const Duration(milliseconds: 300), (_) async {
      try {
        final status = await updateChannel.invokeMapMethod('updateProgress');
        if (mounted && downloading) {
          setState(
            () => progress =
                ((status?['bytes'] as num? ?? 0) / widget.release.size).clamp(
                  0,
                  1,
                ),
          );
        }
      } catch (_) {
        /* Download completion owns the visible error. */
      }
    });
    try {
      path = await updateChannel.invokeMethod<String>(
        'downloadUpdate',
        widget.release.arguments,
      );
      if (path == null) throw StateError('下载未完成');
    } on PlatformException catch (e) {
      if (mounted) setState(() => error = e.message ?? '下载失败，请重试');
    } catch (_) {
      if (mounted) setState(() => error = '下载失败，请重试');
    } finally {
      timer?.cancel();
      if (mounted) setState(() => downloading = false);
    }
    if (mounted && path != null) await install();
  }

  Future<void> install() async {
    setState(() {
      installing = true;
      error = null;
    });
    try {
      final result = await updateChannel.invokeMethod<String>('installUpdate', {
        ...widget.release.arguments,
        'path': path,
      });
      if (mounted) {
        setState(
          () => error = result == 'permission_required'
              ? '请允许安装此来源的应用，返回后点击“继续安装”。'
              : '已打开系统安装器，请完成安装；取消后可继续使用当前版本。',
        );
      }
    } on PlatformException catch (e) {
      if (mounted) {
        setState(() {
          path = null;
          error = e.message ?? '无法打开安装器，请重试';
        });
      }
    } finally {
      if (mounted) setState(() => installing = false);
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !downloading && !installing,
    child: AlertDialog(
      title: Text('发现新版本 ${widget.release.name}'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(widget.release.notes),
            const SizedBox(height: 12),
            Text(
              '安装包 ${(widget.release.size / 1024 / 1024).toStringAsFixed(1)} MB',
            ),
            if (downloading) ...[
              const SizedBox(height: 16),
              LinearProgressIndicator(value: progress),
              Text('正在下载 ${(progress * 100).round()}%'),
            ],
            if (error != null) note(error!),
          ],
        ),
      ),
      actions: [
        if (downloading)
          TextButton(
            onPressed: () async {
              await updateChannel.invokeMethod('cancelUpdate');
            },
            child: const Text('取消下载'),
          ),
        if (!downloading && !installing)
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('稍后'),
          ),
        FilledButton(
          onPressed: downloading || installing
              ? null
              : path == null
              ? download
              : install,
          child: Text(
            installing
                ? '正在打开…'
                : path == null
                ? '下载更新'
                : '继续安装',
          ),
        ),
      ],
    ),
  );
}

class UpdateTile extends StatefulWidget {
  const UpdateTile({super.key});
  @override
  State<UpdateTile> createState() => _UpdateTileState();
}

class _UpdateTileState extends State<UpdateTile> {
  String version = '';
  bool checking = false;
  @override
  void initState() {
    super.initState();
    UpdateService()
        .installed()
        .then((info) {
          if (mounted) {
            setState(
              () => version = '${info['versionName']} (${info['versionCode']})',
            );
          }
        })
        .catchError((Object _) {});
  }

  @override
  Widget build(BuildContext context) => Card(
    child: ListTile(
      leading: const Icon(Icons.system_update_outlined),
      title: const Text('检查更新'),
      subtitle: Text(
        checking
            ? '正在检查…'
            : version.isEmpty
            ? '查看新版本'
            : '当前版本 $version',
      ),
      trailing: checking
          ? const SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          : const Icon(Icons.chevron_right),
      onTap: checking
          ? null
          : () async {
              setState(() => checking = true);
              await checkForUpdate(context);
              if (mounted) setState(() => checking = false);
            },
    ),
  );
}
