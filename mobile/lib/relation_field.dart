import 'package:flutter/material.dart';

import 'api.dart';
import 'ui.dart';

class InlineRelationField extends StatefulWidget {
  final PalantirApi api;
  final String label;
  final Map<String, dynamic> options;
  final List<String> selected;
  final bool multiple, enabled;
  final ValueChanged<List<Map<String, dynamic>>> onChanged;
  const InlineRelationField({
    super.key,
    required this.api,
    required this.label,
    required this.options,
    required this.selected,
    required this.onChanged,
    this.multiple = false,
    this.enabled = true,
  });
  @override
  State<InlineRelationField> createState() => _InlineRelationFieldState();
}

class _InlineRelationFieldState extends State<InlineRelationField> {
  bool open = false, loading = false;
  String query = '';
  String? error;
  int request = 0;
  final known = <String, Map<String, dynamic>>{};
  List<Map<String, dynamic>>? remote;
  void remember() {
    for (final item in [
      ...maps(widget.options['items']),
      ...maps(widget.options['selectedItems']),
    ]) {
      known['${item['id']}'] = item;
    }
  }

  Future<void> search(String value) async {
    final current = ++request;
    setState(() {
      query = value;
      error = null;
    });
    final url = widget.options['search_url']?.toString();
    if (url == null) return;
    setState(() => loading = true);
    try {
      final uri = Uri.parse(url);
      final result = await widget.api.get(
        uri
            .replace(
              queryParameters: {
                ...uri.queryParameters,
                for (final e in mapOf(widget.options['search_context']).entries)
                  'context[${e.key}]': '${e.value}',
                url.startsWith('/ai/') ? 'search' : 'q': value,
              },
            )
            .toString(),
      );
      if (!mounted || current != request) return;
      setState(() {
        remote = maps(result['items'] ?? result['projects']);
        for (final item in remote!) {
          known['${item['id']}'] = item;
        }
      });
    } catch (e) {
      if (mounted && current == request) setState(() => error = '$e');
    } finally {
      if (mounted && current == request) setState(() => loading = false);
    }
  }

  String label(Map<String, dynamic> item) =>
      textOf(item['label'] ?? item['name'] ?? item['title']);
  @override
  Widget build(BuildContext context) {
    remember();
    final shown = (remote ?? maps(widget.options['items']))
        .where(
          (i) =>
              remote != null ||
              label(i).toLowerCase().contains(query.toLowerCase()),
        )
        .toList();
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        children: [
          InkWell(
            key: ValueKey('relation-${widget.label}'),
            onTap: !widget.enabled
                ? null
                : () {
                    setState(() => open = !open);
                    if (open) search('');
                  },
            borderRadius: BorderRadius.circular(12),
            child: InputDecorator(
              isEmpty: widget.selected.isEmpty,
              decoration: InputDecoration(
                labelText: widget.label,
                floatingLabelBehavior: FloatingLabelBehavior.always,
                enabled: widget.enabled,
                suffixIcon: Icon(open ? Icons.expand_less : Icons.expand_more),
              ),
              child: Text(
                widget.selected.isEmpty
                    ? '请选择'
                    : widget.selected
                          .map(
                            (id) => known[id] == null ? id : label(known[id]!),
                          )
                          .join('、'),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ),
          if (open)
            Card(
              margin: const EdgeInsets.only(top: 4),
              child: Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.all(8),
                    child: TextField(
                      decoration: const InputDecoration(
                        hintText: '输入关键词后搜索',
                        prefixIcon: Icon(Icons.search),
                      ),
                      onSubmitted: search,
                    ),
                  ),
                  if (loading) const LinearProgressIndicator(),
                  if (error != null)
                    Padding(
                      padding: const EdgeInsets.all(8),
                      child: errorBox(error!),
                    ),
                  if (!loading && error == null && shown.isEmpty)
                    const Padding(
                      padding: EdgeInsets.all(12),
                      child: Text('暂无可选项'),
                    ),
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxHeight: 220),
                    child: ListView(
                      shrinkWrap: true,
                      children: shown
                          .map(
                            (item) => CheckboxListTile(
                              dense: true,
                              value: widget.selected.contains('${item['id']}'),
                              title: Text(label(item)),
                              onChanged: !widget.enabled
                                  ? null
                                  : (checked) {
                                      final ids = widget.multiple
                                          ? widget.selected.toSet()
                                          : <String>{};
                                      if (checked == true) {
                                        ids.add('${item['id']}');
                                      } else {
                                        ids.remove('${item['id']}');
                                      }
                                      widget.onChanged(
                                        ids
                                            .map(
                                              (id) =>
                                                  known[id] ??
                                                  {'id': id, 'label': id},
                                            )
                                            .toList(),
                                      );
                                      if (!widget.multiple) {
                                        setState(() => open = false);
                                      }
                                    },
                            ),
                          )
                          .toList(),
                    ),
                  ),
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton(
                      onPressed: () => setState(() => open = false),
                      child: const Text('收起'),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
