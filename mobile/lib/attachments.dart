import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'api.dart';
import 'ui.dart';

const android = MethodChannel('palantir/android');
Future<UploadFile?> selectUpload() async {
  try {
    final result = await android.invokeMapMethod<String, dynamic>('pickFile');
    if (result == null) return null;
    return UploadFile(result['name'] as String, result['bytes'] as Uint8List);
  } on PlatformException catch (e) {
    throw ApiFailure(e.message ?? '文件选择失败');
  }
}

Future<void> openExternal(BuildContext context, Uri uri) async {
  if (!['https', 'http', 'tel'].contains(uri.scheme)) {
    toast(context, '不支持此链接');
    return;
  }
  try {
    await android.invokeMethod('openExternal', {'url': uri.toString()});
  } on PlatformException catch (e) {
    if (context.mounted) toast(context, e.message ?? '无法打开链接');
  }
}

class AttachmentPage extends StatefulWidget {
  final PalantirApi api;
  final String path, name;
  const AttachmentPage({
    super.key,
    required this.api,
    required this.path,
    required this.name,
  });
  @override
  State<AttachmentPage> createState() => _AttachmentPageState();
}

class _AttachmentPageState extends State<AttachmentPage> {
  String? pdf, error;
  Uint8List? image;
  int pages = 0, page = 0;
  bool loading = true;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    if (pdf != null) android.invokeMethod('closePdf', {'id': pdf});
    super.dispose();
  }

  Future<void> load() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final bytes = await widget.api.download(widget.path);
      if (!mounted) return;
      if (bytes.length >= 4 && String.fromCharCodes(bytes.take(4)) == '%PDF') {
        final result = await android.invokeMapMethod<String, dynamic>(
          'openPdf',
          {'bytes': bytes},
        );
        final id = result!['id'] as String;
        if (!mounted) {
          await android.invokeMethod('closePdf', {'id': id});
          return;
        }
        pdf = id;
        pages = result['pages'] as int;
        await render(0);
      } else {
        setState(() {
          image = bytes;
          loading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          error = e is PlatformException ? e.message : '$e';
          loading = false;
        });
      }
    }
  }

  Future<void> render(int index) async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final bytes = await android.invokeMethod<Uint8List>('renderPdf', {
        'id': pdf,
        'page': index,
      });
      if (mounted) {
        setState(() {
          image = bytes;
          page = index;
        });
      }
    } on PlatformException catch (e) {
      if (mounted) setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(widget.name)),
    body: Column(
      children: [
        if (loading) const LinearProgressIndicator(),
        if (error != null)
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                errorBox(error!),
                OutlinedButton(
                  onPressed: () => pdf == null ? load() : render(page),
                  child: const Text('重试'),
                ),
              ],
            ),
          ),
        Expanded(
          child: image == null
              ? const SizedBox.shrink()
              : InteractiveViewer(
                  minScale: .5,
                  maxScale: 5,
                  child: Center(
                    child: Image.memory(
                      image!,
                      gaplessPlayback: true,
                      errorBuilder: (c, e, s) => note('暂不支持此附件格式。请在网页端下载原文件。'),
                    ),
                  ),
                ),
        ),
        if (pdf != null)
          SafeArea(
            top: false,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                IconButton(
                  tooltip: '上一页',
                  onPressed: !loading && page > 0
                      ? () => render(page - 1)
                      : null,
                  icon: const Icon(Icons.chevron_left),
                ),
                Text('${page + 1} / $pages'),
                IconButton(
                  tooltip: '下一页',
                  onPressed: !loading && page + 1 < pages
                      ? () => render(page + 1)
                      : null,
                  icon: const Icon(Icons.chevron_right),
                ),
              ],
            ),
          ),
      ],
    ),
  );
}
