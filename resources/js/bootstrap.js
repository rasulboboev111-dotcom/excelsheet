// lodash больше не импортируется: его никто не использовал в коде
// (window._ только присваивался, никем не читался) — это экономит
// 91 КБ initial-JS чанка lodash. Если понадобится — импортировать
// точечно: import debounce from 'lodash/debounce'.

import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// CSRF: при истечении сессии (по умолчанию 120 минут) Laravel ротирует токен.
// Кука XSRF-TOKEN в этот момент уже устаревшая — запрос падает с 419, и
// пользовательские правки молча теряются (UI считает их сохранёнными).
// Берём актуальный токен из <meta name="csrf-token"> на каждый запрос.
const csrfMeta = document.querySelector('meta[name="csrf-token"]');
if (csrfMeta) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfMeta.getAttribute('content');
}

// На 419 (CSRF mismatch / session expired) принудительно перезагружаем
// страницу — Inertia перерисует app.blade.php с новым csrf_token(), и
// следующая правка сохранится. Без reload'а юзер видел бы «всё ок», а
// после F5 правки откатывались бы (см. updateData в SheetController).
window.axios.interceptors.response.use(
    (r) => r,
    (error) => {
        if (error?.response?.status === 419) {
            window.location.reload();
        }
        return Promise.reject(error);
    }
);
