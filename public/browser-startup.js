(function () {
    'use strict';

    if (!Object.hasOwn) {
        Object.defineProperty(Object, 'hasOwn', {
            configurable: true,
            writable: true,
            value: function (object, property) {
                return Object.prototype.hasOwnProperty.call(object, property);
            }
        });
    }

    if (window.CSS && !window.CSS.supports('color', 'oklch(0% 0 0)')) {
        var legacyStyle = document.createElement('style');
        legacyStyle.id = 'legacy-browser-base';
        legacyStyle.textContent = ':root{--radius-sm:6px;--radius-md:10px;--radius-lg:14px;--shadow-sm:0 1px 3px rgba(15,23,42,.08);--shadow-md:0 8px 24px rgba(15,23,42,.09)}h1,h2,h3,p{margin:0}button,input,select,textarea{font-size:inherit;line-height:inherit}';
        document.head.appendChild(legacyStyle);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var app = document.getElementById('app');
        if (!app || app.hasChildNodes()) return;
        var message;
        var timer = setTimeout(function () {
            if (app.hasChildNodes()) return;
            message = document.createElement('section');
            message.setAttribute('role', 'alert');
            message.style.cssText = 'position:fixed;z-index:10000;left:16px;right:16px;bottom:24px;max-width:640px;margin:0 auto;padding:20px;background:#fff;color:#151b22;border:1px solid #d6dde5;border-radius:10px;font:14px/1.7 sans-serif;box-shadow:0 8px 24px #0002';
            var text = document.createElement('p');
            text.textContent = '页面未能完成加载，请重试。若仍无法进入，请升级浏览器，或使用新版 Edge / Chrome；360 浏览器请选择极速模式。';
            var retry = document.createElement('button');
            retry.textContent = '重新加载';
            retry.style.cssText = 'padding:8px 16px;background:#246b95;color:white;border:0;border-radius:6px;cursor:pointer';
            retry.addEventListener('click', function () { window.location.reload(); });
            message.appendChild(text);
            message.appendChild(retry);
            document.body.appendChild(message);
        }, 20000);
        var observer = new MutationObserver(function () {
            if (!app.hasChildNodes()) return;
            clearTimeout(timer);
            if (message) message.remove();
            observer.disconnect();
        });
        observer.observe(app, { childList: true });
    });
}());
