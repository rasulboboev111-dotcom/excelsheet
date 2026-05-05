// lodash больше не импортируется: его никто не использовал в коде
// (window._ только присваивался, никем не читался) — это экономит
// 91 КБ initial-JS чанка lodash. Если понадобится — импортировать
// точечно: import debounce from 'lodash/debounce'.

import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
