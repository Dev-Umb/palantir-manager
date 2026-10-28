import 'package:flutter/material.dart';

import 'api.dart';
import 'attachments.dart';
import 'ui.dart';

class QuotationCard extends StatefulWidget {
  final PalantirApi api;
  final String runId;
  final Map<String, dynamic> artifact;
  const QuotationCard({
    super.key,
    required this.api,
    required this.runId,
    required this.artifact,
  });
  @override
  State<QuotationCard> createState() => _QuotationCardState();
}

class _QuotationCardState extends State<QuotationCard> {
  late Map<String, dynamic> artifact;
  late Map<String, dynamic> values;
  bool busy = false;
  String? feedback;
  String? invalidField;
  final fieldKeys = <String, GlobalKey>{};
  bool editing = false;
  String get endpoint =>
      '/ai/runs/${Uri.encodeComponent(widget.runId)}/quotations/${Uri.encodeComponent('${artifact['id']}')}';
  bool get generated => mapOf(artifact['data'])['generated'] == true;
  @override
  void initState() {
    super.initState();
    artifact = widget.artifact;
    values = {...mapOf(artifact['data'])};
    values['tax_rate'] ??= '13';
    values['shipping'] ??= '含运费';
    values['items'] = maps(values['items'])
        .map((item) => <String, dynamic>{...item})
        .toList();
    if ((values['items'] as List).isEmpty) {
      values['items'] = <Map<String, dynamic>>[{}];
    }
  }

  Widget field(
    Map<String, dynamic> target,
    String key,
    String label, {
    String? id,
  }) {
    final name = id ?? key;
    return Padding(
      key: fieldKeys.putIfAbsent(name, () => GlobalKey()),
      padding: const EdgeInsets.only(top: 10),
      child: key == 'unit'
          ? DropdownButtonFormField<String>(
              key: ValueKey('quote-$name'),
              initialValue:
                  const [
                    '吨',
                    '套',
                    '吨日',
                    '件',
                    '台',
                    '米',
                    '平方米',
                  ].contains(target[key])
                  ? target[key]
                  : null,
              decoration: InputDecoration(
                labelText: label,
                errorText: invalidField == name ? '请选择计价单位' : null,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                ),
              ),
              items: const ['吨', '套', '吨日', '件', '台', '米', '平方米']
                  .map(
                    (unit) => DropdownMenuItem(
                      value: unit,
                      child: Text(unit, style: const TextStyle(fontSize: 13)),
                    ),
                  )
                  .toList(),
              onChanged: busy || generated
                  ? null
                  : (value) => setState(() {
                      target[key] = value;
                      invalidField = null;
                      feedback = null;
                    }),
            )
          : TextFormField(
              key: ValueKey('quote-$name'),
              initialValue: '${target[key] ?? ''}',
              enabled: !busy && !generated,
              style: const TextStyle(fontSize: 13, color: ink),
              decoration: InputDecoration(
                labelText: label,
                errorText: invalidField == name ? '请填写$label' : null,
                filled: true,
                fillColor: Colors.white,
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 12,
                ),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: const BorderSide(color: Color(0xffdce4eb)),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: const BorderSide(color: Color(0xffdce4eb)),
                ),
              ),
              onChanged: (value) {
                target[key] = value;
                if (invalidField == name) setState(() => invalidField = null);
              },
            ),
    );
  }

  bool validate() {
    final required = <String, String>{
      'title': '报价标题',
      'contact': '联系人',
      'phone': '联系电话',
      'date': '报价日期',
    };
    for (var i = 0; i < maps(values['items']).length; i++) {
      for (final entry in {
        'name': '物资名称',
        'price': '综合单价',
        'unit': '计价单位',
      }.entries) {
        required['items.$i.${entry.key}'] = '产品${i + 1}的${entry.value}';
      }
    }
    for (final entry in required.entries) {
      final parts = entry.key.split('.');
      final value = parts.length == 3
          ? maps(values['items'])[int.parse(parts[1])][parts[2]]
          : values[entry.key];
      if ('${value ?? ''}'.trim().isNotEmpty) continue;
      setState(() {
        invalidField = entry.key;
        feedback = '请补充${entry.value}后生成';
        if (entry.key == 'date' || entry.key.startsWith('items.')) {
          editing = true;
        }
      });
      WidgetsBinding.instance.addPostFrameCallback((_) {
        final target = fieldKeys[entry.key]?.currentContext;
        if (target != null) {
          Scrollable.ensureVisible(
            target,
            duration: const Duration(milliseconds: 250),
            alignment: .15,
          );
        }
      });
      return false;
    }
    return true;
  }

  Future<void> preview() async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => AttachmentPage(
          api: widget.api,
          path: '$endpoint/download?format=pdf',
          name: '报价单预览',
        ),
      ),
    );
  }

  Future<void> share() async {
    final format = await showModalBottomSheet<String>(
      context: context,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.picture_as_pdf_outlined),
              title: const Text('分享 PDF（推荐）'),
              onTap: () => Navigator.pop(context, 'pdf'),
            ),
            ListTile(
              leading: const Icon(Icons.description_outlined),
              title: const Text('分享 Word'),
              onTap: () => Navigator.pop(context, 'docx'),
            ),
          ],
        ),
      ),
    );
    if (format == null || !mounted) return;
    setState(() {
      busy = true;
      feedback = null;
    });
    try {
      final bytes = await widget.api.download(
        '$endpoint/download${format == 'pdf' ? '?format=pdf' : ''}',
      );
      await android.invokeMethod('shareFile', {
        'bytes': bytes,
        'name': '报价单.$format',
        'mime': format == 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      });
      if (mounted) setState(() => feedback = '已打开分享面板，请选择微信或其他应用并确认发送。');
    } catch (e) {
      if (mounted) setState(() => feedback = '分享失败，请重试：$e');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> act({String format = 'docx'}) async {
    if (!generated && !validate()) return;
    setState(() {
      busy = true;
      feedback = null;
    });
    try {
      if (generated) {
        final bytes = await widget.api.download(
          '$endpoint/download${format == 'pdf' ? '?format=pdf' : ''}',
        );
        final saved = await android.invokeMethod<bool>('saveFile', {
          'bytes': bytes,
          'name': '报价单.$format',
          'mime': format == 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        });
        if (mounted) {
          setState(
            () => feedback = saved == true ? '报价单已保存到所选位置' : '已取消保存，可重新下载',
          );
        }
      } else {
        final payload = {
          ...values,
          'items': maps(values['items'])
              .map(
                (item) => {
                  ...item,
                  'material_price': '${item['material_price'] ?? ''}'.isEmpty
                      ? null
                      : item['material_price'],
                  'processing_price':
                      '${item['processing_price'] ?? ''}'.isEmpty
                      ? null
                      : item['processing_price'],
                },
              )
              .toList(),
        };
        final response = await widget.api.send(endpoint, payload);
        if (mounted) {
          setState(() {
            artifact = mapOf(response['artifact']);
            widget.artifact.addAll(artifact);
          });
        }
      }
    } catch (e) {
      if (mounted) setState(() => feedback = '$e');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Widget summary(String label, String value) => Container(
    padding: const EdgeInsets.symmetric(vertical: 12),
    decoration: const BoxDecoration(
      border: Border(bottom: BorderSide(color: Color(0xffedf0f3))),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Text(
            label,
            style: const TextStyle(fontSize: 12, color: Colors.blueGrey),
          ),
        ),
        const SizedBox(width: 12),
        Flexible(
          child: Text(
            value,
            textAlign: TextAlign.right,
            style: const TextStyle(
              fontSize: 13,
              color: ink,
              fontWeight: FontWeight.w500,
            ),
          ),
        ),
      ],
    ),
  );
  @override
  Widget build(BuildContext context) => Material(
    color: Colors.transparent,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            const Expanded(
              child: Text(
                '固定模板报价单',
                style: TextStyle(
                  fontSize: 15,
                  color: ink,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
              decoration: BoxDecoration(
                color: generated
                    ? const Color(0xffe8f3ed)
                    : const Color(0xfffbf3e3),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Text(
                generated ? '已生成' : '待确认',
                style: TextStyle(
                  fontSize: 11,
                  color: generated
                      ? const Color(0xff39866b)
                      : const Color(0xffa6792e),
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        if (!generated) ...[
          field(values, 'title', '报价标题'),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: field(values, 'contact', '联系人')),
              const SizedBox(width: 10),
              Expanded(child: field(values, 'phone', '联系电话')),
            ],
          ),
        ] else ...[
          summary('报价标题', '${values['title'] ?? ''}'),
          summary(
            '联系人 · 电话',
            '${values['contact'] ?? ''} · ${values['phone'] ?? ''}',
          ),
          summary('报价日期', '${values['date'] ?? ''}'),
        ],
        const SizedBox(height: 12),
        for (final item in maps(values['items']))
          summary(
            '${item['name'] ?? '待填写产品'}',
            '${item['price'] ?? '待填写'} 元/${'${item['unit'] ?? ''}'.isEmpty ? '待确认单位' : item['unit']}',
          ),
        summary('税运口径', '含税${values['tax_rate']}% · ${values['shipping']}'),
        if (!generated) ...[
          TextButton.icon(
            key: const Key('quote-edit'),
            onPressed: busy ? null : () => setState(() => editing = !editing),
            icon: Icon(
              editing ? Icons.expand_less : Icons.edit_outlined,
              size: 16,
            ),
            label: Text(
              editing ? '收起明细' : '编辑产品、单位及其他信息',
              style: const TextStyle(fontSize: 12),
            ),
          ),
          if (editing) ...[
            field(values, 'date', '报价日期（YYYY-MM-DD）'),
            for (var i = 0; i < maps(values['items']).length; i++) ...[
              Padding(
                padding: const EdgeInsets.only(top: 14),
                child: Text(
                  '产品 ${i + 1}',
                  style: const TextStyle(
                    fontSize: 12,
                    color: steel,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              field(
                (values['items'] as List)[i],
                'name',
                '物资名称',
                id: 'items.$i.name',
              ),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: field(
                      (values['items'] as List)[i],
                      'price',
                      '综合单价',
                      id: 'items.$i.price',
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: field(
                      (values['items'] as List)[i],
                      'unit',
                      '计价单位',
                      id: 'items.$i.unit',
                    ),
                  ),
                ],
              ),
              ExpansionTile(
                title: const Text(
                  '材料费、加工费（可选）',
                  style: TextStyle(fontSize: 12),
                ),
                children: [
                  field(
                    (values['items'] as List)[i],
                    'material_price',
                    '材料费',
                    id: 'items.$i.material_price',
                  ),
                  field(
                    (values['items'] as List)[i],
                    'processing_price',
                    '加工费',
                    id: 'items.$i.processing_price',
                  ),
                ],
              ),
            ],
            if ((values['items'] as List).length < 3)
              TextButton(
                onPressed: busy
                    ? null
                    : () => setState(
                        () =>
                            (values['items'] as List).add(<String, dynamic>{}),
                      ),
                child: const Text('添加产品'),
              ),
            field(values, 'tax_rate', '税率（模板固定13%）'),
            field(values, 'shipping', '运费口径（模板固定含运费）'),
          ],
        ],
        if (feedback != null)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Text(
              feedback!,
              style: TextStyle(
                fontSize: 12,
                color: generated ? steel : const Color(0xffb6544a),
              ),
            ),
          ),
        const SizedBox(height: 10),
        if (generated) ...[
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  key: const ValueKey('quote-preview'),
                  onPressed: busy ? null : preview,
                  icon: const Icon(Icons.visibility_outlined, size: 18),
                  label: const Text('预览报价单'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: OutlinedButton.icon(
                  key: const ValueKey('quote-share'),
                  onPressed: busy ? null : share,
                  icon: const Icon(Icons.ios_share, size: 18),
                  label: const Text('分享报价单'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
        ],
        FilledButton(
          style: FilledButton.styleFrom(
            backgroundColor: steel,
            foregroundColor: Colors.white,
            minimumSize: const Size(0, 46),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(11),
            ),
          ),
          onPressed: busy ? null : () => act(),
          child: busy
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: Colors.white,
                  ),
                )
              : Text(
                  generated ? '下载盖章报价单 DOCX' : '确认并生成盖章报价单',
                  style: const TextStyle(fontSize: 13),
                ),
        ),
        if (generated)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: FilledButton(
              style: FilledButton.styleFrom(
                backgroundColor: const Color(0xffedf4f8),
                foregroundColor: steel,
                minimumSize: const Size(0, 46),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(11),
                ),
              ),
              onPressed: busy ? null : () => act(format: 'pdf'),
              child: const Text('下载盖章报价单 PDF', style: TextStyle(fontSize: 13)),
            ),
          ),
        const Padding(
          padding: EdgeInsets.only(top: 12),
          child: Text(
            '原模板排版与公章保持不变，导出文字为黑色。',
            style: TextStyle(fontSize: 10, color: Colors.blueGrey),
          ),
        ),
      ],
    ),
  );
}
