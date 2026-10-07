import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/foundation.dart';

import 'session_store.dart';

class ApiFailure implements Exception {
  final String message;
  final int status;
  final Map<String, dynamic> errors;
  ApiFailure(this.message, {this.status = 0, this.errors = const {}});
  @override
  String toString() => message;
}

class UploadFile {
  final String name;
  final Uint8List bytes;
  const UploadFile(this.name, this.bytes);
}

class PalantirApi extends ChangeNotifier {
  final Uri base;
  final HttpClient _http = HttpClient()
    ..connectionTimeout = const Duration(seconds: 15)
    ..idleTimeout = const Duration(seconds: 60);
  final Map<String, Cookie> _cookies = {};
  final SessionStore? sessionStore;
  Future<void> _storageQueue = Future.value();
  bool _remember = false;
  bool authenticating = false;
  bool sessionClearFailed = false;
  int _generation = 0;
  String? _version;
  Map<String, dynamic>? user;
  Map<String, dynamic> session = {};
  final assistantState = <String, dynamic>{};
  PalantirApi(String address, {this.sessionStore}) : base = Uri.parse(address) {
    if (!['http', 'https'].contains(base.scheme) || base.host.isEmpty) {
      throw ArgumentError('请输入有效的后端地址');
    }
    if (base.scheme == 'http' &&
        !['10.0.2.2', '127.0.0.1', 'localhost'].contains(base.host)) {
      throw ArgumentError('非本机服务必须使用 HTTPS');
    }
  }
  bool can(String permission) =>
      (session['permissions'] as List? ?? []).contains(permission);
  bool get canUpload => session['can_upload_contracts'] == true;
  bool get canProcurement => session['can_read_procurement'] == true;
  Uri uri(String path) {
    final given = Uri.parse(path);
    if (given.hasAuthority) {
      if (given.host != base.host ||
          given.port != base.port ||
          given.scheme != base.scheme) {
        throw ApiFailure('拒绝将登录信息发送到其他服务');
      }
      return given;
    }
    return base.resolve(path);
  }

  Future<void> bootstrap() async {
    await _request(
      'GET',
      user == null ? '/login' : '/settings',
      isPage: true,
      allowGuest: true,
    );
  }

  void _applyPage(Map<String, dynamic> props) {
    final auth = mapOf(props['auth']);
    final nextUser = auth['user'] == null ? null : mapOf(auth['user']);
    final sameUser = user?['id'] == nextUser?['id'];
    if (!sameUser) assistantState.clear();
    user = nextUser;
    session = {
      'permissions': auth['permissions'] ?? [],
      'roles': maps(auth['roles']).map((r) => r['label']).toList(),
      'can_upload_contracts':
          props['canUploadContracts'] == true ||
          (!props.containsKey('canUploadContracts') && sameUser && canUpload),
      'can_read_procurement': maps(props['nav'])
          .any((n) => n['key'] == 'hub' && n['visible'] == true),
    };
    notifyListeners();
  }

  Future<void> _storage(Future<void> Function() action) {
    final next = _storageQueue.then((_) => action());
    _storageQueue = next.catchError((Object _) {});
    return next.catchError((Object _) {
      throw ApiFailure('无法保存或清除设备上的登录信息，请重试。');
    });
  }

  Future<void> _persist() async {
    if (!_remember || sessionStore == null) return;
    final snapshot = jsonEncode({
      'origin': base.origin,
      'cookies': _cookies.values.map((cookie) => cookie.toString()).toList(),
    });
    await _storage(() => sessionStore!.write(snapshot));
  }

  Future<bool> restoreSession() async {
    if (sessionStore == null) return false;
    await _storageQueue;
    final String? saved;
    try {
      saved = await sessionStore!.read();
    } catch (_) {
      throw ApiFailure('无法读取设备上的登录信息，请重试或重新登录。');
    }
    if (saved == null) return false;
    try {
      final data = jsonDecode(saved) as Map<String, dynamic>;
      if (data['origin'] != base.origin) throw const FormatException();
      final cookies = (data['cookies'] as List)
          .cast<String>()
          .map(Cookie.fromSetCookieValue)
          .toList();
      _cookies.clear();
      _receiveCookies(cookies);
      if (_cookies.isEmpty) throw const FormatException();
    } catch (_) {
      await clearSession();
      return false;
    }
    _remember = true;
    try {
      await page('/settings');
      await _loadAssistantAccess();
      await _persist();
      return user != null;
    } on ApiFailure catch (error) {
      if (error.status == 401 || error.status == 419) return false;
      rethrow;
    }
  }

  Future<void> _loadAssistantAccess() async {
    if (can('ai.harness.view')) {
      try {
        await page('/ai');
      } on ApiFailure catch (e) {
        // AI availability must not block an otherwise valid account login.
        if (e.status == 401 || e.status == 419) rethrow;
      }
    }
  }

  Future<void> login(
    String email,
    String password, {
    bool remember = true,
  }) async {
    authenticating = true;
    notifyListeners();
    try {
      await clearSession();
      await bootstrap();
      await _request(
        'POST',
        '/login',
        body: {
          'email': email.trim(),
          'password': password,
          'remember': remember,
        },
        allowGuest: true,
      );
      if (user == null) throw ApiFailure('账号或密码不正确。', status: 422);
      await _loadAssistantAccess();
      _remember = remember;
      try {
        await _persist();
      } catch (_) {
        await clearSession();
        rethrow;
      }
    } finally {
      authenticating = false;
      notifyListeners();
    }
  }

  Future<void> logout() async {
    try {
      await _request('POST', '/logout', body: {}, allowGuest: true);
    } finally {
      await clearSession();
    }
  }

  Future<void> clearSession() async {
    _generation++;
    _remember = false;
    user = null;
    session = {};
    assistantState.clear();
    _cookies.clear();
    _version = null;
    try {
      if (sessionStore != null) await _storage(() => sessionStore!.clear());
      sessionClearFailed = false;
    } catch (_) {
      sessionClearFailed = true;
      rethrow;
    } finally {
      notifyListeners();
    }
  }

  bool _expired(Cookie cookie) =>
      cookie.maxAge == 0 ||
      (cookie.expires != null &&
          !cookie.expires!.isAfter(DateTime.now().toUtc()));

  void _receiveCookies(List<Cookie> cookies) {
    for (final cookie in cookies) {
      final domain = cookie.domain?.replaceFirst(RegExp(r'^\.'), '');
      if (domain != null &&
          domain != base.host &&
          !base.host.endsWith('.$domain')) {
        continue;
      }
      if (cookie.maxAge != null) {
        cookie.expires = DateTime.now().toUtc().add(
          Duration(seconds: cookie.maxAge!),
        );
        cookie.maxAge = null;
      }
      if (_expired(cookie)) {
        _cookies.remove(cookie.name);
      } else {
        _cookies[cookie.name] = cookie;
      }
    }
    _cookies.removeWhere((_, cookie) => _expired(cookie));
  }

  void _ensureCurrent(int generation) {
    if (generation != _generation) {
      throw ApiFailure('登录状态已变化，请重试。', status: 401);
    }
  }

  void _authenticate(HttpClientRequest request) {
    _cookies.removeWhere((_, cookie) => _expired(cookie));
    final requestPath = request.uri.path.isEmpty ? '/' : request.uri.path;
    final valid = _cookies.values.where((cookie) {
      final path = cookie.path ?? '/';
      return (!cookie.secure || request.uri.scheme == 'https') &&
          (requestPath == path ||
              requestPath.startsWith(path.endsWith('/') ? path : '$path/'));
    }).toList();
    request.cookies.addAll(valid);
    final xsrf = valid
        .where((cookie) => cookie.name == 'XSRF-TOKEN')
        .firstOrNull;
    if (xsrf != null) {
      request.headers.set('X-XSRF-TOKEN', Uri.decodeComponent(xsrf.value));
    }
  }

  Future<Map<String, dynamic>> page(
    String path, {
    String? component,
    List<String>? only,
  }) => _request(
    'GET',
    path,
    isPage: true,
    component: component,
    only: only == null ? null : {...only, 'auth', 'nav', 'errors'}.toList(),
  );

  Future<Map<String, dynamic>> recordPage(
    String object,
    String? id, {
    bool editing = false,
  }) => page(
    Uri(
      path: '/objects/$object',
      queryParameters: {'record': ?id, 'per_page': '1'},
    ).toString(),
    component: 'Ontology/Index',
    only: [
      'currentObject',
      'selectedRecord',
      'can',
      if (editing) 'relationOptions',
    ],
  );
  Future<Map<String, dynamic>> get(String path) => request('GET', path);
  Future<Map<String, dynamic>> send(
    String path,
    Map<String, dynamic> body, {
    String method = 'POST',
  }) => request(method, path, body: body);
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) => _request(method, path, body: body);

  Future<Map<String, dynamic>> _request(
    String method,
    String path, {
    Map<String, dynamic>? body,
    bool isPage = false,
    String? component,
    List<String>? only,
    bool allowGuest = false,
    int remaining = 4,
  }) async {
    final generation = _generation;
    if (remaining == 0) throw ApiFailure('服务器跳转异常，请重试。');
    try {
      final req = await _http.openUrl(method, uri(path));
      _ensureCurrent(generation);
      req.followRedirects = false;
      req.headers.set(HttpHeaders.acceptHeader, 'application/json');
      req.headers.set('X-Requested-With', 'XMLHttpRequest');
      if (isPage) {
        req.headers.set('X-Inertia', 'true');
        if (_version != null) req.headers.set('X-Inertia-Version', _version!);
        if (component != null && only != null) {
          req.headers.set('X-Inertia-Partial-Component', component);
          req.headers.set('X-Inertia-Partial-Data', only.join(','));
        }
      }
      if (uri(path).path == '/login') {
        req.headers.set('Referer', uri('/login').toString());
      }
      _authenticate(req);
      if (body != null) {
        req.headers.contentType = ContentType.json;
        req.write(jsonEncode(body));
      }
      final res = await req.close().timeout(const Duration(seconds: 110));
      _ensureCurrent(generation);
      _receiveCookies(res.cookies);
      await _persist();
      final text = await utf8.decoder.bind(res).join();
      _ensureCurrent(generation);
      if (isPage && res.statusCode == 409) {
        final location = res.headers.value('X-Inertia-Location');
        if (location != null) {
          uri(location);
          final version =
              res.headers.value('X-Inertia-Version') ??
              await _recoverPageVersion(location, generation);
          if (version == _version) {
            throw ApiFailure('服务器页面版本未更新，请重试。');
          }
          _version = version;
          return await _request(
            'GET',
            location,
            isPage: true,
            component: component,
            only: only,
            allowGuest: allowGuest,
            remaining: remaining - 1,
          );
        }
      }
      if ([301, 302, 303].contains(res.statusCode)) {
        final location = res.headers.value('location');
        if (location == null) throw ApiFailure('服务器跳转异常，请重试。');
        final destination = uri(path).resolve(location);
        uri(destination.toString());
        if (!allowGuest && destination.path == '/login') {
          await _check(401, {});
        }
        return await _request(
          'GET',
          destination.toString(),
          isPage: true,
          allowGuest: allowGuest,
          remaining: remaining - 1,
        );
      }
      Map<String, dynamic>? data;
      try {
        final decoded = jsonDecode(text);
        if (decoded is Map) data = Map<String, dynamic>.from(decoded);
      } catch (_) {}
      await _check(res.statusCode, data ?? {});
      _ensureCurrent(generation);
      if (res.statusCode == 204 && !isPage) return {};
      if (res.statusCode != 200 && res.statusCode != 201 || data == null) {
        throw ApiFailure('服务器返回内容异常，请重试。');
      }
      if (!isPage) return data;
      if (data['component'] is! String || data['props'] is! Map) {
        throw ApiFailure('服务器页面数据异常，请重试。');
      }
      _version = data['version'] as String?;
      final props = mapOf(data['props']);
      if (!allowGuest && mapOf(props['auth'])['user'] == null) {
        await _check(401, {});
      }
      if (only != null && only.any((key) => !props.containsKey(key))) {
        throw ApiFailure('服务器页面数据不完整，请重试。');
      }
      _applyPage(props);
      final errors = mapOf(props['errors']);
      if (errors.isNotEmpty) await _check(422, {'errors': errors});
      if (uri(path).path.startsWith('/objects/') &&
          uri(path).queryParameters.containsKey('record') &&
          props['selectedRecord'] == null) {
        throw ApiFailure('记录不存在或已不可访问。', status: 404);
      }
      return props;
    } on ApiFailure {
      rethrow;
    } catch (_) {
      throw ApiFailure('无法连接服务器，请检查网络后重试。输入已保留。');
    }
  }

  Future<String> _recoverPageVersion(String location, int generation) async {
    final request = await _http.getUrl(uri(location));
    _ensureCurrent(generation);
    request.followRedirects = false;
    request.headers.set(HttpHeaders.acceptHeader, 'text/html');
    _authenticate(request);
    final response = await request.close().timeout(
      const Duration(seconds: 110),
    );
    _ensureCurrent(generation);
    _receiveCookies(response.cookies);
    await _checkRedirect(response);
    await _check(response.statusCode, {});
    final bytes = BytesBuilder();
    await for (final chunk in response) {
      bytes.add(chunk);
      if (bytes.length > 2 * 1024 * 1024) {
        throw ApiFailure('服务器页面版本检查失败，请重试。');
      }
    }
    _ensureCurrent(generation);
    final page = RegExp(
      r'''<script\b(?=[^>]*\bdata-page\s*=\s*["']app["'])(?=[^>]*\btype\s*=\s*["']application/json["'])[^>]*>(.*?)</script\s*>''',
      caseSensitive: false,
      dotAll: true,
    ).firstMatch(utf8.decode(bytes.takeBytes()));
    final data = page == null ? null : jsonDecode(page.group(1)!);
    if (data is! Map || data['version'] is! String) {
      throw ApiFailure('服务器页面版本检查失败，请重试。');
    }
    await _persist();
    return data['version'] as String;
  }

  Future<void> _checkRedirect(HttpClientResponse response) async {
    if (response.statusCode >= 300 && response.statusCode < 400) {
      final location = response.headers.value('location');
      if (location != null) {
        final target = uri(location);
        if (target.path == '/login') await _check(401, {});
      }
      throw ApiFailure('服务器跳转异常，请重试。');
    }
  }

  Future<void> _check(int status, Map<String, dynamic> data) async {
    if (status == 401) {
      await clearSession();
      throw ApiFailure('登录已失效，请重新登录。', status: status);
    }
    if (status == 419) {
      await clearSession();
      throw ApiFailure('会话已过期，请重新登录后再提交。', status: status);
    }
    if (status >= 400) {
      final errors = Map<String, dynamic>.from(data['errors'] ?? {});
      final error = errors.isEmpty ? null : errors.values.first;
      throw ApiFailure(
        error != null
            ? (error is List ? error.join('；') : error.toString())
            : (data['message'] ??
                      (status == 403
                          ? '当前账号没有操作权限。'
                          : status == 404
                          ? '记录不存在或已不可访问。'
                          : '请求失败，请稍后重试。'))
                  .toString(),
        status: status,
        errors: errors,
      );
    }
  }

  Future<Map<String, dynamic>> multipart(
    String path,
    Map<String, dynamic> values, {
    String method = 'POST',
  }) async {
    final generation = _generation;
    final boundary = 'palantir-${DateTime.now().microsecondsSinceEpoch}';
    final chunks = BytesBuilder();
    void text(String s) => chunks.add(utf8.encode(s));
    void part(String key, dynamic value) {
      if (value is Map) {
        value.forEach((k, v) => part('$key[$k]', v));
        return;
      }
      if (value is List) {
        for (var i = 0; i < value.length; i++) {
          part('$key[$i]', value[i]);
        }
        return;
      }
      text('--$boundary\r\n');
      if (value is UploadFile) {
        final name = value.name.replaceAll(RegExp(r'["\r\n]'), '_');
        final mime = name.toLowerCase().endsWith('.pdf')
            ? 'application/pdf'
            : name.toLowerCase().endsWith('.png')
            ? 'image/png'
            : 'image/jpeg';
        text(
          'Content-Disposition: form-data; name="$key"; filename="$name"\r\nContent-Type: $mime\r\n\r\n',
        );
        chunks.add(value.bytes);
        text('\r\n');
      } else {
        text(
          'Content-Disposition: form-data; name="$key"\r\n\r\n${value ?? ''}\r\n',
        );
      }
    }

    if (method != 'POST') part('_method', method);
    values.forEach(part);
    text('--$boundary--\r\n');
    try {
      final req = await _http.postUrl(uri(path));
      _ensureCurrent(generation);
      req.followRedirects = false;
      _authenticate(req);
      req.headers.set('Accept', 'application/json');
      req.headers.set(
        'Content-Type',
        'multipart/form-data; boundary=$boundary',
      );
      req.add(chunks.takeBytes());
      final res = await req.close().timeout(const Duration(seconds: 110));
      _ensureCurrent(generation);
      _receiveCookies(res.cookies);
      await _persist();
      final body = await utf8.decoder.bind(res).join();
      _ensureCurrent(generation);
      await _checkRedirect(res);
      final data = jsonDecode(body) as Map<String, dynamic>;
      await _check(res.statusCode, data);
      _ensureCurrent(generation);
      return data;
    } on ApiFailure {
      rethrow;
    } catch (_) {
      throw ApiFailure('文件提交失败，已保留选择的文件，请重试。');
    }
  }

  Future<Uint8List> download(String path) async {
    final generation = _generation;
    try {
      final req = await _http.getUrl(uri(path));
      _ensureCurrent(generation);
      req.followRedirects = false;
      _authenticate(req);
      final res = await req.close();
      _ensureCurrent(generation);
      _receiveCookies(res.cookies);
      await _persist();
      await _checkRedirect(res);
      await _check(res.statusCode, {});
      if (res.statusCode != 200) throw ApiFailure('附件暂时无法打开');
      final bytes = await consolidateHttpClientResponseBytes(res);
      _ensureCurrent(generation);
      return bytes;
    } on ApiFailure {
      rethrow;
    } catch (_) {
      throw ApiFailure('附件加载失败，请重试。');
    }
  }

  @override
  void dispose() {
    _http.close(force: true);
    super.dispose();
  }
}

Map<String, dynamic> mapOf(dynamic value) =>
    value is Map ? Map<String, dynamic>.from(value) : {};
List<Map<String, dynamic>> maps(dynamic value) => (value is List ? value : [])
    .whereType<Map>()
    .map((e) => Map<String, dynamic>.from(e))
    .toList();
String textOf(dynamic value) {
  if (value == null || value == '') return '未填写';
  if (value is List) return value.map(textOf).join('、');
  if (value is Map) {
    return (value['label'] ?? value['name'] ?? value['title'] ?? '').toString();
  }
  return value.toString();
}

String amount(dynamic value, {bool wan = false}) {
  final n = num.tryParse('${value ?? ''}');
  if (n == null) return '未填写';
  return wan ? '${(n / 10000).toStringAsFixed(2)} 万' : n.toStringAsFixed(2);
}

String progress(Map<String, dynamic> p) {
  final a = num.tryParse('${p['occurred_amount']}'),
      b = num.tryParse('${p['paid_amount']}');
  return a == null || a <= 0 || b == null
      ? '—'
      : '${(b / a * 100).toStringAsFixed(2)}%';
}
