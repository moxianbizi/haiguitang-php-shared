/* ============================================================
 * 海龟汤馆 · 全栈版前端
 * 路由 + API + 页面渲染 + WebSocket
 * ============================================================ */

// ---------- 工具 ----------
const $ = (sel, root = document) => root.querySelector(sel);
const el = (tag, props = {}, children = []) => {
  const n = document.createElement(tag);
  Object.entries(props).forEach(([k, v]) => {
    if (k === "class") n.className = v;
    else if (k === "html") n.innerHTML = v;
    else if (k.startsWith("on")) n.addEventListener(k.slice(2), v);
    else n.setAttribute(k, v);
  });
  (Array.isArray(children) ? children : [children]).forEach((c) => {
    if (c == null) return;
    n.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
  });
  return n;
};

function escapeHtml(str) {
  return String(str ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function escapeJs(str) {
  return JSON.stringify(str ?? "").slice(1, -1);
}

/**
 * 安全渲染 Markdown 为 HTML。
 * - 使用 marked 解析表格/加粗/斜体/列表等语法
 * - 保留颜色版汤源的 <span style="color: blue;">（蓝色规则）等安全 HTML
 * - 过滤危险标签（script/iframe/on* 事件），防 XSS
 * - 给"解析"段落自动套楷体（规则怪谈类汤用楷体区分解析内容）
 * @param {string} md 原始 markdown 文本
 * @returns {string} 安全的 HTML
 */
function renderMd(md) {
  if (!md) return "";
  // 初始化 marked（只初始化一次）
  if (typeof marked !== "undefined" && !renderMd._inited) {
    marked.setOptions({
      gfm: true,        // GitHub Flavored Markdown（表格、删除线等）
      breaks: true,     // 单换行也转 <br>
      headerIds: false, // 不给标题加 id
      mangle: false,
    });
    renderMd._inited = true;
  }
  let html;
  if (typeof marked !== "undefined") {
    let src = String(md ?? "");
    // 预处理：marked v12 严格遵循 CommonMark，CJK 字符相邻的 **bold** 无法识别
    // （如「**【渡边温泉】**编号」「**老板娘视角 **」会渲染出字面 **）。
    // 这里把单行内、不含 * 的 **content** 提前转成 <strong>，marked 见到 HTML 标签会原样保留。
    // 不处理跨行 **（content 含换行），交给 marked 正常解析。
    src = src.replace(/\*\*([^*\n]+)\*\*/g, "<strong>$1</strong>");
    // Markdown 中图片路径 ./海龟汤图片/ 对应 web 路径 /soups-img/
    src = src.replace(/\.\/海龟汤图片\//g, "/soups-img/");
    html = marked.parse(src);
    if (typeof DOMPurify !== "undefined") {
      html = DOMPurify.sanitize(html, {
        ALLOWED_TAGS: [
          "span", "em", "strong", "img", "br", "p", "a", "code", "pre",
          "blockquote", "ul", "ol", "li", "h1", "h2", "h3", "h4", "h5", "h6",
          "table", "thead", "tbody", "tr", "th", "td", "del", "sup", "sub",
          "hr", "div", "dl", "dt", "dd",
        ],
        ALLOWED_ATTR: [
          "style", "alt", "src", "class", "href", "target", "colspan", "rowspan",
          "align", "valign",
        ],
        ALLOW_DATA_ATTR: false,
      });
    } else {
      html = html.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, "")
                 .replace(/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/gi, "")
                 .replace(/<object\b[^>]*>/gi, "").replace(/<\/object>/gi, "")
                 .replace(/<embed\b[^>]*>/gi, "")
                 .replace(/\son\w+\s*=\s*"[^"]*"/gi, "")
                 .replace(/\son\w+\s*=\s*'[^']*'/gi, "")
                 .replace(/\son\w+\s*=\s*[^\s>]+/gi, "")
                 .replace(/javascript:/gi, "");
    }
  } else {
    // marked 加载失败时回退到纯文本转义
    html = escapeHtml(md).replace(/\n/g, "<br>");
  }

  // 楷体处理：把含"解析"关键词的段落套上 kaiti class
  html = html.replace(/<p>([^<]*(?:解析|梗概|结局)[^<]*)<\/p>/gi, (m, inner) => {
    return `<p class="kaiti">${inner}</p>`;
  });
  html = html.replace(/<p>(怪谈解析[^<]*)<\/p>/gi, '<p class="kaiti">$1</p>');

  return html;
}

function toast(msg, type = "") {
  const t = $("#toast");
  if (!t) return;
  t.textContent = msg;
  t.className = "toast show " + type;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => (t.className = "toast " + type), 2600);
}

// ---------- 全局状态 ----------
const store = {
  user: null,
  soups: [],
  seasons: [],
  filtered: [],
  selected: null,
  search: "",
  season: "",
  squareSoups: [],
  squareSearch: "",
  aiKey: localStorage.getItem("hgt_deepseek_key") || "",
  csrfToken: "",
  // 单人模式每碗汤的问答历史（按 soup_id 存）
  aiHistory: {},
  pollTimer: null,
  pollLastId: 0,
  pollInFlight: false,
  currentRoomCode: null,
};

// 共享主机常不支持 URL 重写，统一走 index.php?r=/api/xxx，兼容 .htaccess 环境
const API_BASE = window.API_BASE || "/index.php?r=";

const API = {
  resolve(path) {
    return path.startsWith("http") ? path : API_BASE + path;
  },
  async get(path) {
    const r = await fetch(this.resolve(path), { credentials: "same-origin" });
    return r;
  },
  async json(path, opts = {}) {
    const headers = { "Content-Type": "application/json", ...opts.headers };
    if (store.csrfToken) headers["X-CSRF-Token"] = store.csrfToken;
    const r = await fetch(this.resolve(path), {
      credentials: "same-origin",
      headers,
      ...opts,
    });
    let data;
    try { data = await r.json(); } catch { data = {}; }
    return { ok: r.ok, status: r.status, data };
  },
  post(path, body) {
    return this.json(path, { method: "POST", body: JSON.stringify(body) });
  },
  put(path, body) {
    return this.json(path, { method: "PUT", body: JSON.stringify(body) });
  },
  del(path) {
    return this.json(path, { method: "DELETE" });
  },
};

// ---------- DeepSeek Key 管理 ----------
const KeyMgr = {
  get() { return store.aiKey; },
  set(k) {
    store.aiKey = (k || "").trim();
    if (store.aiKey) localStorage.setItem("hgt_deepseek_key", store.aiKey);
    else localStorage.removeItem("hgt_deepseek_key");
  },
  has() { return !!store.aiKey; },
  getConfig() {
    try { return JSON.parse(localStorage.getItem("hgt_ai_config") || "{}"); } catch { return {}; }
  },
  setConfig(cfg) {
    localStorage.setItem("hgt_ai_config", JSON.stringify(cfg));
  },
  getProviderPayload() {
    const cfg = this.getConfig();
    return {
      provider: cfg.provider || "deepseek",
      base_url: cfg.baseUrl || "",
      model: cfg.model || "",
    };
  },
  async test(key) {
    const k = (key || store.aiKey).trim();
    if (!k) return { ok: false, msg: "请先填写 Key" };
    if (!store.soups.length) await loadSoups();
    if (!store.soups.length) return { ok: false, msg: "汤数据未加载，无法测试" };
    const testSoup = store.soups.find((s) => s.base) || store.soups[0];
    const { ok, data } = await API.post("/api/ai/ask", {
      soup_id: testSoup.id,
      question: "测试",
      api_key: k,
      ...this.getProviderPayload(),
    });
    if (ok && data.answer) return { ok: true, msg: "连接成功" };
    if (data.code === "missing_key") return { ok: false, msg: "Key 为空" };
    if (data.code === "invalid_key") return { ok: false, msg: "Key 无效或已过期" };
    if (data.code === "insufficient_balance") return { ok: false, msg: "账户余额不足" };
    if (data.code === "upstream_error" || data.code === "parse_error")
      return { ok: true, msg: "Key 格式有效（上游返回：" + (data.error || "").slice(0, 40) + "）" };
    return { ok: false, msg: data.error || "测试失败" };
  },
};

// ---------- 路由 ----------
function route() {
  const hash = location.hash.replace(/^#/, "") || "/";
  // 清空弹窗并恢复滚动，避免 closeAllModals -> closeSettings -> route 递归
  const root = $("#modalRoot");
  if (root) root.innerHTML = "";
  document.body.style.overflow = "";
  if (store.pollTimer) { clearInterval(store.pollTimer); store.pollTimer = null; }

  if (hash === "/" || hash === "") return renderHome();
  if (hash === "/auth") return renderAuth();
  if (hash === "/rooms") return renderRooms();
  if (hash === "/square") return renderSquare();
  if (hash.startsWith("/room/")) return renderRoom(hash.slice("/room/".length));
  if (hash.startsWith("/lzcxroom/")) return renderLzcxRoom(hash.slice("/lzcxroom/".length));
  if (hash.startsWith("/soup/")) return renderSoupPage(hash.slice("/soup/".length));
  if (hash.startsWith("/user/")) return renderUserPage(hash.slice("/user/".length));
  if (hash === "/profile") return renderProfile();
  if (hash.startsWith("/admin")) return renderAdmin(hash);
  renderHome();
}

window.addEventListener("hashchange", route);

// ---------- Header ----------
function headerHtml(active = "") {
  const u = store.user;
  const keyOk = KeyMgr.has();
  return `
    <header class="header">
      <div class="container header-inner">
        <a href="#/" class="logo">
          <div class="logo-icon">🍲</div>
          <span>海龟汤馆</span>
        </a>
        <nav class="nav">
          <a href="#/" class="nav-item ${active === "home" ? "active" : ""}">汤馆</a>
          <a href="#/square" class="nav-item ${active === "square" ? "active" : ""}">广场</a>
          <a href="#/rooms" class="nav-item ${active === "rooms" ? "active" : ""}">房间</a>
          ${u ? `<a href="#/profile" class="nav-item ${active === "profile" ? "active" : ""}">我的</a>` : ""}
          ${u && u.is_admin ? `<a href="#/admin" class="nav-item ${active === "admin" ? "active" : ""}">后台</a>` : ""}
        </nav>
        <div class="header-actions">
          <button class="btn-icon ${keyOk ? "has-key" : ""}" onclick="openSettings()" title="AI 设置">⚙</button>
          ${u
            ? `<a href="#/profile" class="user-chip"><span class="user-avatar">${escapeHtml(u.username.slice(0, 1).toUpperCase())}</span>${escapeHtml(u.username)}</a>`
            : `<a href="#/auth" class="user-chip">登录</a>`}
          <button class="mobile-menu-btn" onclick="toggleMobileNav(event)" aria-label="菜单">☰</button>
        </div>
      </div>
      <div class="mobile-nav" id="mobileNav" onclick="hideMobileNav(event)">
        <a href="#/" class="${active === "home" ? "active" : ""}">🏠 汤馆</a>
        <a href="#/square" class="${active === "square" ? "active" : ""}">🌐 广场</a>
        <a href="#/rooms" class="${active === "rooms" ? "active" : ""}">🎮 房间</a>
        ${u ? `<a href="#/profile" class="${active === "profile" ? "active" : ""}">👤 我的</a>` : ""}
        ${u && u.is_admin ? `<a href="#/admin" class="${active === "admin" ? "active" : ""}">⚙ 后台</a>` : ""}
      </div>
    </header>
    <nav class="bottom-nav">
      <a href="#/" class="${active === "home" ? "active" : ""}">🏠<br>汤馆</a>
      <a href="#/square" class="${active === "square" ? "active" : ""}">🌐<br>广场</a>
      <a href="#/rooms" class="${active === "rooms" ? "active" : ""}">🎮<br>房间</a>
      ${u ? `<a href="#/profile" class="${active === "profile" ? "active" : ""}">👤<br>我的</a>` : `<a href="#/auth">🔑<br>登录</a>`}
    </nav>
  `;
}

window.toggleMobileNav = (e) => {
  e.stopPropagation();
  const nav = $("#mobileNav");
  if (!nav) return;
  nav.classList.toggle("open");
};

window.hideMobileNav = (e) => {
  if (e && e.target.tagName === "A") {
    const nav = $("#mobileNav");
    if (nav) nav.classList.remove("open");
  }
};

document.addEventListener("click", (e) => {
  const nav = $("#mobileNav");
  if (!nav || !nav.classList.contains("open")) return;
  if (!nav.contains(e.target) && !e.target.closest(".mobile-menu-btn")) {
    nav.classList.remove("open");
  }
});

// ---------- 首页 ----------
async function renderHome() {
  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("home")}
      <section class="hero container">
        <div class="hero-badge">悬疑推理 · 汤面与汤底</div>
        <h1>海龟汤馆</h1>
        <p>看汤面，问线索，揭汤底。收录的每一碗汤都可单独让 AI 主持对答。</p>
        <div class="curator">整理人 · 长安</div>
        <div class="search-box">
          <span class="search-icon">🔍</span>
          <input type="text" id="searchInput" placeholder="搜索标题、汤面或系列…" value="${escapeHtml(store.search)}" />
        </div>
        ${store.user ? `<div style="margin-top:20px"><button class="btn btn-primary" onclick="openSoupEditor()">写一碗汤</button></div>` : `<p style="margin-top:20px;color:var(--text-3);font-size:0.85rem"><a href="#/auth" style="color:var(--accent)">登录</a> 后可投稿 · <a href="#/square" style="color:var(--accent)">自制汤广场</a></p>`}
      </section>
      <div class="stats-bar container" id="statsBar">
        <div class="stat"><strong>${store.soups.length}</strong>收录汤数</div>
        <div class="stat"><strong>${store.seasons.length}</strong>系列/季</div>
        <div class="stat"><strong>${KeyMgr.has() ? "已配置" : "未配置"}</strong>AI 主持人</div>
      </div>
      <div id="homeContent"></div>
      <footer class="footer container">
        <span>海龟汤馆 · 整理人长安</span>
      </footer>
      <div id="modalRoot"></div>
    </div>
  `;

  const input = $("#searchInput");
  if (input) {
    input.addEventListener("input", (e) => {
      store.search = e.target.value;
      applyFilters();
      renderHomeList();
      const next = $("#searchInput");
      if (next) { next.focus(); next.setSelectionRange(store.search.length, store.search.length); }
    });
  }
  await loadSoups();
  renderStats();
  renderFilters();
  renderHomeList();
}

function applyFilters() {
  const q = store.search.toLowerCase();
  store.filtered = store.soups.filter((s) => {
    const matchesQ = !q ||
      (s.title || "").toLowerCase().includes(q) ||
      (s.excerpt || "").toLowerCase().includes(q) ||
      (s.season || "").toLowerCase().includes(q);
    const matchesSeason = !store.season || s.season === store.season;
    return matchesQ && matchesSeason;
  });
}

function renderSkeletonGrid() {
  return `<div class="container"><div class="grid" style="padding-bottom:28px">
    ${Array.from({ length: 6 }).map(() => `
      <article class="card" style="pointer-events:none;min-height:160px">
        <div class="skeleton" style="width:90px;height:22px;border-radius:999px;margin-bottom:14px"></div>
        <div class="skeleton" style="width:70%;height:22px;margin-bottom:10px"></div>
        <div class="skeleton" style="width:100%;height:14px;margin-bottom:8px"></div>
        <div class="skeleton" style="width:90%;height:14px;margin-bottom:8px"></div>
        <div class="skeleton" style="width:60%;height:14px"></div>
      </article>
    `).join("")}
  </div></div>`;
}

function renderStats() {
  const bar = $("#statsBar");
  if (!bar) return;
  bar.innerHTML = `
    <div class="stat"><strong>${store.soups.length}</strong>收录汤数</div>
    <div class="stat"><strong>${store.seasons.length}</strong>系列/季</div>
    <div class="stat"><strong>${KeyMgr.has() ? "已配置" : "未配置"}</strong>AI 主持人</div>
  `;
}

async function loadSoups() {
  if (store.soups.length) return;
  $("#homeContent").innerHTML = renderSkeletonGrid();
  const { ok, data } = await API.json("/api/soups");
  if (!ok) {
    $("#homeContent").innerHTML = `<div class="empty"><div class="empty-icon">🍲</div><p>加载失败，请确认后端已启动</p></div>`;
    return;
  }
  store.soups = data.soups || [];
  store.seasons = data.seasons || [];
  applyFilters();
  renderStats();
  renderFilters();
}

function renderFilters() {
  const wrap = $(".filters");
  if (wrap) wrap.remove();
  const hero = $(".hero");
  if (!hero) return;
  const f = document.createElement("div");
  f.className = "filters container";
  f.innerHTML = `
    <button class="filter-chip ${store.season === "" ? "active" : ""}" data-season="">全部</button>
    ${store.seasons.map((s) => `
      <button class="filter-chip ${store.season === s ? "active" : ""}" data-season="${escapeHtml(s)}">${escapeHtml(s)}</button>
    `).join("")}
  `;
  f.querySelectorAll("[data-season]").forEach((btn) => {
    btn.addEventListener("click", () => setSeason(btn.dataset.season));
  });
  hero.after(f);
}

function renderHomeList() {
  const c = $("#homeContent");
  if (!c) return;
  const items = store.filtered;
  if (!items.length) {
    c.innerHTML = `<div class="empty"><div class="empty-icon">🍲</div><p>没有找到匹配的海龟汤</p></div>`;
    return;
  }
  // 按季节分组
  const groups = {};
  items.forEach((s) => {
    const k = s.season || "其他";
    (groups[k] = groups[k] || []).push(s);
  });
  const ordered = Object.entries(groups).sort((a, b) =>
    a[0].localeCompare(b[0], undefined, { numeric: true })
  );

  c.innerHTML = ordered.map(([season, list]) => `
    <div class="container">
      <h2 class="section-title">${escapeHtml(season)}</h2>
      <div class="grid">
        ${list.map((s) => `
          <article class="card" onclick="openSoup(${s.id})">
            <span class="card-tag">${escapeHtml(s.season)}${s.episode ? " · " + escapeHtml(s.episode) : ""}</span>
            <h3>${escapeHtml(s.title)}</h3>
            <p>${escapeHtml(s.excerpt || "")}</p>
          </article>
        `).join("")}
      </div>
    </div>
  `).join("");
}

window.setSeason = (s) => { store.season = s; applyFilters(); renderFilters(); renderHomeList(); };

// ---------- 自制汤广场 ----------
async function renderSquare() {
  const q0 = store.squareSearch || "";
  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("square")}
      <section class="hero container">
        <div class="hero-badge">社区创作 · 自制汤</div>
        <h1>自制汤广场</h1>
        <p>社区玩家写的海龟汤。登录后投稿，经审核通过即在此展示。</p>
        <div class="search-box">
          <span class="search-icon">🔍</span>
          <input type="text" id="squareSearchInput" placeholder="搜索标题或汤面…" value="${escapeHtml(q0)}" />
        </div>
        ${store.user
          ? `<div style="margin-top:20px"><button class="btn btn-primary" onclick="openSoupEditor()">写一碗汤</button></div>`
          : `<p style="margin-top:20px;color:var(--text-3);font-size:0.85rem"><a href="#/auth" style="color:var(--accent)">登录</a> 后可投稿</p>`}
      </section>
      <div class="stats-bar container" id="squareStats"><div class="stat"><strong>…</strong>自制汤数</div></div>
      <div id="squareContent">${renderSkeletonGrid()}</div>
      <footer class="footer container"><span>海龟汤馆 · 自制汤广场</span></footer>
      <div id="modalRoot"></div>
    </div>
  `;
  const input = $("#squareSearchInput");
  if (input) {
    input.addEventListener("input", (e) => {
      store.squareSearch = e.target.value;
      renderSquareList();
      const next = $("#squareSearchInput");
      if (next) { next.focus(); next.setSelectionRange(store.squareSearch.length, store.squareSearch.length); }
    });
  }
  await loadSquareSoups();
}

async function loadSquareSoups() {
  const { ok, data } = await API.json("/api/soups?source=community");
  if (!ok) {
    $("#squareContent").innerHTML = `<div class="empty"><div class="empty-icon">🍲</div><p>加载失败</p></div>`;
    return;
  }
  store.squareSoups = data.soups || [];
  const bar = $("#squareStats");
  if (bar) bar.innerHTML = `<div class="stat"><strong>${store.squareSoups.length}</strong>自制汤数</div>`;
  renderSquareList();
}

function renderSquareList() {
  const c = $("#squareContent");
  if (!c) return;
  const q = (store.squareSearch || "").toLowerCase();
  const items = (store.squareSoups || []).filter((s) =>
    !q ||
    (s.title || "").toLowerCase().includes(q) ||
    (s.excerpt || "").toLowerCase().includes(q) ||
    (s.surface || "").toLowerCase().includes(q)
  );
  if (!items.length) {
    c.innerHTML = `<div class="empty"><div class="empty-icon">🍲</div><p>${q ? "没有找到匹配的自制汤" : "广场还没有自制汤，快来写第一碗吧"}</p></div>`;
    return;
  }
  c.innerHTML = `<div class="container"><div class="grid" style="padding-bottom:28px">
    ${items.map((s) => `
      <article class="card" onclick="openSoup(${s.id})">
        ${s.author_username ? `<span class="card-author">✍️ ${escapeHtml(s.author_username)}</span>` : ""}
        <span class="card-tag">${escapeHtml(s.season || "自制")}${s.episode ? " · " + escapeHtml(s.episode) : ""}</span>
        <h3>${escapeHtml(s.title)}</h3>
        <p>${escapeHtml(s.excerpt || "")}</p>
      </article>
    `).join("")}
  </div></div>`;
}

// ---------- 用户主页 ----------
async function renderUserPage(id) {
  const uid = parseInt(id, 10);
  if (!uid || uid <= 0) { location.hash = "#/"; return; }
  $("#app").innerHTML = `<div class="page">${headerHtml("")}<div class="container"><div class="spinner" style="margin:40px auto"></div></div><div id="modalRoot"></div></div>`;
  const { ok, data } = await API.json(`/api/users/${uid}`);
  if (!ok) {
    $("#app").innerHTML = `<div class="page">${headerHtml("")}<div class="container"><div class="empty"><div class="empty-icon">👤</div><p>${escapeHtml(data.error || "用户不存在")}</p></div></div><div id="modalRoot"></div></div>`;
    return;
  }
  const u = data.user, st = data.stats, isMe = data.is_me, following = data.following;
  const followBtn = isMe
    ? `<a href="#/profile" class="btn btn-secondary">编辑我的</a>`
    : store.user
      ? `<button class="btn ${following ? "btn-ghost" : "btn-primary"}" id="followBtn" onclick="toggleFollow(${uid})">${following ? "已关注" : "+ 关注"}</button>`
      : `<a href="#/auth" class="btn btn-primary">登录后关注</a>`;
  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("")}
      <div class="container">
        <button class="btn btn-ghost back-btn" onclick="history.back()">← 返回</button>
        <div class="profile-header">
          <div class="avatar">${escapeHtml(u.username.slice(0, 1).toUpperCase())}</div>
          <div class="info">
            <h2>${escapeHtml(u.username)}</h2>
            <p>加入于 ${escapeHtml((u.created_at || "").slice(0, 10))}</p>
          </div>
          ${followBtn}
        </div>
        <div class="profile-grid">
          <div class="profile-card"><div class="profile-stat"><span>汤</span><span class="v">${st.soups}</span></div></div>
          <div class="profile-card"><div class="profile-stat"><span>关注</span><span class="v">${st.following}</span></div></div>
          <div class="profile-card"><div class="profile-stat"><span>粉丝</span><span class="v">${st.followers}</span></div></div>
        </div>
        <h2 class="section-title">TA 的汤</h2>
        <div id="userSoups"></div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;
  const list = data.soups || [];
  const c = $("#userSoups");
  if (!list.length) {
    c.innerHTML = `<div class="empty"><div class="empty-icon">🍲</div><p>还没有发布的汤</p></div>`;
  } else {
    c.innerHTML = `<div class="grid" style="padding-bottom:28px">${list.map((s) => `
      <article class="card" onclick="openSoup(${s.id})">
        <span class="card-tag">${escapeHtml(s.season || "自制")}${s.episode ? " · " + escapeHtml(s.episode) : ""}</span>
        <h3>${escapeHtml(s.title)}</h3>
        <p>${escapeHtml(s.excerpt || "")}</p>
      </article>
    `).join("")}</div>`;
  }
}

window.toggleFollow = async (uid) => {
  if (!store.user) { location.hash = "#/auth"; return; }
  const btn = $("#followBtn");
  if (!btn) return;
  if (btn.textContent.includes("已关注")) {
    const { ok, data } = await API.del(`/api/follow/${uid}`);
    if (!ok) { toast(data.error || "操作失败", "err"); return; }
    btn.textContent = "+ 关注";
    btn.className = "btn btn-primary";
    toast("已取关", "ok");
  } else {
    const { ok, data } = await API.post(`/api/follow/${uid}`, {});
    if (!ok) { toast(data.error || "操作失败", "err"); return; }
    btn.textContent = "已关注";
    btn.className = "btn btn-ghost";
    toast("已关注", "ok");
  }
};

// ---------- 详情页 + 单人 AI ----------
async function openSoup(id) {
  // 改为独立路由全屏页面，浏览器后退也能返回
  location.hash = "#/soup/" + id;
}
window.openSoup = openSoup;

async function renderSoupPage(id) {
  const soupId = parseInt(id, 10);
  if (!soupId || soupId <= 0) { location.hash = "#/"; return; }

  // 先渲染骨架，避免白屏
  $("#app").innerHTML = `
    <div class="page soup-detail-page">
      ${headerHtml("")}
      <div class="container soup-container">
        <div class="skeleton" style="width:60%;height:32px;margin:24px 0 8px"></div>
        <div class="skeleton" style="width:40%;height:14px;margin-bottom:24px"></div>
        <div class="skeleton" style="width:100%;height:14px;margin-bottom:8px"></div>
        <div class="skeleton" style="width:90%;height:14px;margin-bottom:8px"></div>
        <div class="skeleton" style="width:70%;height:14px"></div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;

  const { ok, data } = await API.json(`/api/soups/${soupId}`);
  if (!ok) {
    $("#app").innerHTML = `
      <div class="page soup-detail-page">
        ${headerHtml("")}
        <div class="container soup-container">
          <button class="btn btn-ghost back-btn" onclick="history.back()">← 返回</button>
          <div class="empty"><div class="empty-icon">🍲</div><p>${escapeHtml(data.error || "加载失败")}</p></div>
        </div>
      </div>
    `;
    return;
  }
  store.selected = data;
  // 自制汤且非自己且已登录：取关注状态供作者区按钮初始显示
  if (data.author_id && store.user && store.user.id !== data.author_id) {
    const fr = await API.json(`/api/follow/${data.author_id}`);
    if (fr.ok) data._following = !!fr.data.following;
  }
  renderSoupPageContent(data);
}

function renderSoupPageContent(soup) {
  const hist = store.aiHistory[soup.id] || [];
  const keyOk = KeyMgr.has();
  // 作者区关注按钮：仅自制汤、已登录、非自己时显示
  const authorFollowBtn = (soup.author_id && store.user && store.user.id !== soup.author_id)
    ? `<button class="btn ${soup._following ? "btn-ghost" : "btn-primary"}" id="followBtn" onclick="toggleFollow(${soup.author_id})">${soup._following ? "已关注" : "+ 关注"}</button>`
    : "";

  // 空汤面/汤底的友好提示
  const hasSurface = !!(soup.surface && soup.surface.trim());
  const hasBase = !!(soup.base && soup.base.trim());
  const surfaceText = hasSurface
    ? renderMd(soup.surface)
    : `<span class="empty-hint">（本汤暂无独立汤面${soup.host_manual ? "，请直接阅读主持人手册" : ""}）</span>`;
  const baseBlock = hasBase ? `
        <div class="section-label base">
          <span>汤底</span>
          <button class="reveal-toggle" id="revealToggle" onclick="revealBase(event)">▶ 点击展开汤底</button>
        </div>
        <div class="text-block md-body reveal collapsed" id="baseBlock" style="display:none">${renderMd(soup.base)}</div>` : '';

  $("#app").innerHTML = `
    <div class="page soup-detail-page">
      ${headerHtml("")}
      <div class="container soup-container">
        <button class="btn btn-ghost back-btn" onclick="history.back()">← 返回</button>

        <div class="soup-detail-header">
          <span class="card-tag">${escapeHtml(soup.season)}${soup.episode ? " · " + escapeHtml(soup.episode) : ""}</span>
          <h1 class="soup-detail-title">${escapeHtml(soup.title)}</h1>
          <div class="modal-meta">${escapeHtml(soup.filename)}</div>
        </div>

        <div class="soup-author">
          ${soup.author_id
            ? `作者：<a href="#/user/${soup.author_id}" class="author-link">${escapeHtml(soup.author_username || "匿名")}</a> ${authorFollowBtn}`
            : `<span class="author-official">作者：许二木</span>`}
        </div>

        <div class="section-label">汤面</div>
        <div class="text-block md-body">${surfaceText}</div>

        <div class="section-label ai">向 AI 主持人提问</div>
        <div class="ai-area">
          <p class="ai-hint">
            ${!hasBase
              ? `<span class="warn">本汤暂无汤底，AI 主持人无法作答。</span>`
              : keyOk
                ? "AI 只会回答「是」「否」「无关」，猜中汤底会提示。汤底不会泄露给 AI 之外的任何人。"
                : `<span class="warn">尚未配置 DeepSeek API Key，</span>点击右上角 ⚙ 填入你自己的 Key 后即可提问。`}
          </p>
          <div class="ai-history" id="aiHistory">
            ${hist.length === 0
              ? `<div class="ai-empty">${hasBase ? "还没有提问记录。试试问「主角是男性吗？」" : "本汤无汤底，无法提问。"}</div>`
              : hist.map((t) => `
                <div class="ai-turn">
                  <div class="ai-q">${escapeHtml(t.q)}</div>
                  <div class="ai-a ${classifyAnswer(t.a)}">${escapeHtml(t.a)}</div>
                </div>
              `).join("")}
          </div>
          <div class="ai-input-row">
            <input type="text" id="aiQuestionInput" placeholder="问 AI 一个是非题…" ${(keyOk && hasBase) ? "" : "disabled"} onkeydown="if(event.key==='Enter')askAiSingle(${soup.id})" />
            <button onclick="askAiSingle(${soup.id})" ${(keyOk && hasBase) ? "" : "disabled"}>提问</button>
          </div>
        </div>

        ${baseBlock}

        ${soup.host_manual ? `
        <div class="section-label base">
          <span>主持人手册</span>
          <button class="reveal-toggle" id="manualToggle" onclick="revealManual(event)">▶ 点击展开主持人手册</button>
        </div>
        <div class="text-block md-body reveal collapsed" id="manualBlock" style="display:none">${renderMd(soup.host_manual)}</div>` : ''}

        ${soup.extra ? `
        <div class="section-label base">
          <span>其他内容</span>
          <button class="reveal-toggle" id="extraToggle" onclick="revealExtra(event)">▶ 点击展开其他内容</button>
        </div>
        <div class="text-block md-body reveal collapsed" id="extraBlock" style="display:none">${renderMd(soup.extra)}</div>` : ''}

        <div class="soup-detail-actions">
          <button class="btn btn-primary" onclick="newRoomFromSoup(${soup.id})">🎮 开房间</button>
          ${store.user && (store.user.is_admin || store.user.id === soup.author_id) ? `<button class="btn btn-secondary" onclick="openSoupEditor(${soup.id})">✏️ 编辑</button>` : ""}
          ${store.user ? `<a class="btn btn-ghost" href="/api/soups/${soup.id}/download" download>⬇ 下载</a>` : `<a href="#/auth" class="btn btn-ghost">⬇ 登录后下载</a>`}
        </div>

        <div class="comments-section">
          <div class="section-label">评论区</div>
          <div id="commentsList"><div class="spinner" style="margin:12px auto"></div></div>
          ${store.user ? `
          <div class="comment-form">
            <textarea class="input" id="commentInput" rows="2" maxlength="1000" placeholder="发表你的看法…"></textarea>
            <button class="btn btn-primary" onclick="submitComment(${soup.id})">发表</button>
          </div>` : `<p class="ai-hint" style="margin:8px 0"><a href="#/auth">登录</a>后即可评论</p>`}
        </div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;
  window.scrollTo(0, 0);
  loadComments(soup.id);
}

async function loadComments(soupId, page = 1) {
  const { ok, data } = await API.json(`/api/soups/${soupId}/comments?page=${page}`);
  const c = $("#commentsList");
  if (!c) return;
  if (!ok) { c.innerHTML = `<p style="color:var(--text-3)">加载失败</p>`; return; }
  if (!data.comments.length) { c.innerHTML = `<p style="color:var(--text-3);margin:8px 0">暂无评论</p>`; return; }
  c.innerHTML = data.comments.map(cm => {
    const mine = store.user && cm.user_id === store.user.id;
    const admin = store.user && store.user.is_admin;
    return `<div class="comment-item">
      <div class="comment-meta"><strong>${escapeHtml(cm.username)}</strong> · ${escapeHtml(cm.created_at || "")}${mine || admin ? ` <button class="comment-del-btn" onclick="deleteComment(${soupId},${cm.id})">删除</button>` : ""}</div>
      <div class="comment-content">${escapeHtml(cm.content)}</div>
    </div>`;
  }).join("");
}

window.submitComment = async (soupId) => {
  const input = $("#commentInput");
  if (!input) return;
  const content = input.value.trim();
  if (!content) { toast("请输入评论内容", "err"); return; }
  const { ok, data } = await API.post(`/api/soups/${soupId}/comments`, { content });
  if (!ok) { toast(data.error || "评论失败", "err"); return; }
  input.value = "";
  loadComments(soupId);
};

window.deleteComment = async (soupId, commentId) => {
  if (!confirm("确认删除此评论？")) return;
  const { ok, data } = await API.del(`/api/soups/${soupId}/comments/${commentId}`);
  if (!ok) { toast(data.error || "删除失败", "err"); return; }
  loadComments(soupId);
};

window.openSoupEditor = async (soupId) => {
  const isNew = !soupId;
  let soup = { title: '', season: '', episode: '', surface: '', base: '', host_manual: '', extra: '', images: [] };
  if (!isNew) {
    const { ok, data } = await API.json(`/api/soups/${soupId}`);
    if (!ok) { toast(data.error || "加载失败", "err"); return; }
    const draftKey = `soup_draft_${soupId}`;
    const draft = JSON.parse(localStorage.getItem(draftKey) || "null");
    soup = draft || data;
  }
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open" style="max-width:900px">
      <div class="modal-header"><div><h2 class="modal-title">${isNew ? "✍️ 写一碗汤" : "编辑汤题"}</h2>${isNew ? '<p style="color:var(--text-3);font-size:0.85rem;margin:4px 0 0">投稿后需管理员审核通过才会在广场展示</p>' : ''}</div><button class="modal-close" onclick="closeModal(event)">✕</button></div>
      <div class="modal-body" style="max-height:70vh;overflow-y:auto">
        <div class="field"><label>标题</label><input class="input" id="ed_title" value="${escapeHtml(soup.title || '')}" oninput="previewSoupEdit()" /></div>
        <div class="admin-row">
          <div class="field"><label>系列</label><input class="input" id="ed_season" value="${escapeHtml(soup.season || '')}" /></div>
          <div class="field"><label>集</label><input class="input" id="ed_episode" value="${escapeHtml(soup.episode || '')}" /></div>
        </div>
        <div class="editor-tabs">
          <button class="editor-tab active" onclick="switchEditorTab('surface',this)">汤面</button>
          <button class="editor-tab" onclick="switchEditorTab('base',this)">汤底</button>
          <button class="editor-tab" onclick="switchEditorTab('host_manual',this)">主持人手册</button>
          <button class="editor-tab" onclick="switchEditorTab('extra',this)">其他内容</button>
          ${isNew ? '' : `<button class="editor-tab" onclick="switchEditorTab('images',this)">配图</button>`}
        </div>
        <div class="editor-layout">
          <textarea class="input editor-textarea" id="ed_surface" rows="10" placeholder="汤面内容…" oninput="previewSoupEdit()">${escapeHtml(soup.surface || '')}</textarea>
          <textarea class="input editor-textarea" id="ed_base" rows="10" placeholder="汤底内容…" style="display:none" oninput="previewSoupEdit()">${escapeHtml(soup.base || '')}</textarea>
          <textarea class="input editor-textarea" id="ed_host_manual" rows="10" placeholder="主持人手册…" style="display:none" oninput="previewSoupEdit()">${escapeHtml(soup.host_manual || '')}</textarea>
          <textarea class="input editor-textarea" id="ed_extra" rows="10" placeholder="其他内容…" style="display:none" oninput="previewSoupEdit()">${escapeHtml(soup.extra || '')}</textarea>
          ${isNew ? '' : `<div id="ed_images" style="display:none">
            <div id="imageList">${(soup.images || []).map((img, i) => `<div class="img-item"><img src="/soups-img/${escapeHtml(img)}" style="max-width:120px;max-height:80px;border-radius:4px" /><button class="admin-act-btn danger" onclick="deleteSoupImage(${soupId},${i})">删除</button></div>`).join("")}</div>
            ${(soup.images || []).length < 5 ? `<label class="btn btn-secondary" style="cursor:pointer;margin-top:8px">上传图片<input type="file" accept="image/*" style="display:none" onchange="uploadSoupImage(${soupId},this)" /></label>` : "<p>已达5张上限</p>"}
          </div>`}
          <div class="editor-preview md-body" id="edPreview">${renderMd(soup.surface || '')}</div>
        </div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-ghost" onclick="closeModal(event)">取消</button>
        <button class="btn btn-primary" onclick="${isNew ? `saveSoupNew()` : `saveSoupEdit(${soupId})`}">${isNew ? "投稿" : "保存"}</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
  if (!isNew) {
    setInterval(() => {
      const d = { title: $("#ed_title")?.value, surface: $("#ed_surface")?.value, base: $("#ed_base")?.value, host_manual: $("#ed_host_manual")?.value, extra: $("#ed_extra")?.value, season: $("#ed_season")?.value, episode: $("#ed_episode")?.value };
      localStorage.setItem(`soup_draft_${soupId}`, JSON.stringify(d));
    }, 5000);
  }
};

// 新建投稿：POST /api/soups
window.saveSoupNew = async () => {
  const body = {
    title: $("#ed_title")?.value.trim() || "",
    surface: $("#ed_surface")?.value || "",
    base: $("#ed_base")?.value || "",
    host_manual: $("#ed_host_manual")?.value || "",
    extra: $("#ed_extra")?.value || "",
    season: $("#ed_season")?.value || "",
    episode: $("#ed_episode")?.value || "",
  };
  if (!body.title) { toast("标题不能为空", "err"); return; }
  if (!body.surface) { toast("汤面不能为空", "err"); return; }
  const { ok, data } = await API.post("/api/soups", body);
  if (!ok) { toast(data.error || "投稿失败", "err"); return; }
  toast("投稿成功，等待管理员审核后将在广场展示", "ok");
  closeModal();
  location.hash = "#/square";
};

let _editorCurrentTab = "surface";
window.switchEditorTab = (tab, btn) => {
  ["surface", "base", "host_manual", "extra"].forEach(t => {
    const el = $(`#ed_${t}`);
    if (el) el.style.display = t === tab ? "" : "none";
  });
  const imgEl = $("#ed_images");
  if (imgEl) imgEl.style.display = tab === "images" ? "" : "none";
  const previewEl = $("#edPreview");
  if (previewEl) previewEl.style.display = tab === "images" ? "none" : "";
  _editorCurrentTab = tab;
  document.querySelectorAll(".editor-tab").forEach(b => b.classList.remove("active"));
  if (btn) btn.classList.add("active");
  if (tab !== "images") previewSoupEdit();
};

window.previewSoupEdit = () => {
  const el = $(`#ed_${_editorCurrentTab}`);
  const preview = $("#edPreview");
  if (el && preview) preview.innerHTML = renderMd(el.value || "");
};

window.saveSoupEdit = async (soupId) => {
  const body = {
    title: $("#ed_title")?.value.trim() || "",
    surface: $("#ed_surface")?.value || "",
    base: $("#ed_base")?.value || "",
    host_manual: $("#ed_host_manual")?.value || "",
    extra: $("#ed_extra")?.value || "",
    season: $("#ed_season")?.value || "",
    episode: $("#ed_episode")?.value || "",
  };
  if (!body.title) { toast("标题不能为空", "err"); return; }
  const { ok, data } = await API.put(`/api/soups/${soupId}`, body);
  if (!ok) { toast(data.error || "保存失败", "err"); return; }
  localStorage.removeItem(`soup_draft_${soupId}`);
  toast("已保存", "ok");
  closeModal();
  renderSoupPage(soupId);
};

window.uploadSoupImage = async (soupId, input) => {
  if (!input.files || !input.files[0]) return;
  const formData = new FormData();
  formData.append("image", input.files[0]);
  const csrf = store.csrfToken || "";
  const res = await fetch(API_BASE + `/api/soups/${soupId}/images`, { method: "POST", headers: { "X-CSRF-Token": csrf }, body: formData });
  const data = await res.json();
  if (!res.ok || data.error) { toast(data.error || "上传失败", "err"); return; }
  toast("上传成功", "ok");
  openSoupEditor(soupId);
};

window.deleteSoupImage = async (soupId, index) => {
  if (!confirm("确认删除此图片？")) return;
  const { ok, data } = await API.del(`/api/soups/${soupId}/images`, { index });
  if (!ok) { toast(data.error || "删除失败", "err"); return; }
  toast("已删除", "ok");
  openSoupEditor(soupId);
};

function classifyAnswer(ans) {
  const a = (ans || "").trim();
  if (a.includes("猜中")) return "win";
  if (a === "是" || a.startsWith("是")) return "yes";
  if (a === "否" || a.startsWith("否")) return "no";
  if (a.includes("无关")) return "irrelevant";
  return "";
}

function renderSoupModal(soup, revealed) {
  // 已废弃：汤详情改为独立路由全屏页面，见 renderSoupPage / renderSoupPageContent
  renderSoupPageContent(soup);
}

async function askAiSingle(soupId) {
  const input = $("#aiQuestionInput");
  if (!input) return;
  const q = input.value.trim();
  if (!q) return;
  const key = KeyMgr.get();
  if (!key) { toast("请先在右上角 ⚙ 配置 DeepSeek Key", "err"); return; }

  const soup = store.selected;
  if (!soup || soup.id !== soupId) return;

  input.disabled = true;
  const btn = input.nextElementSibling;
  if (btn) { btn.disabled = true; btn.innerHTML = `<span class="spinner sm"></span>`; }

  // 乐观插入问题
  if (!store.aiHistory[soupId]) store.aiHistory[soupId] = [];
  store.aiHistory[soupId].push({ q, a: "思考中…" });
  refreshAiHistory(soupId);

  const { ok, data } = await API.post("/api/ai/ask", {
    soup_id: soupId,
    question: q,
    api_key: key,
    ...KeyMgr.getProviderPayload(),
  });

  const last = store.aiHistory[soupId][store.aiHistory[soupId].length - 1];
  if (ok && data.answer) {
    last.a = data.answer;
  } else {
    last.a = "❌ " + (data.error || "提问失败");
  }
  refreshAiHistory(soupId);

  input.value = "";
  input.disabled = false;
  if (btn) { btn.disabled = false; btn.textContent = "提问"; }
  input.focus();
}
window.askAiSingle = askAiSingle;

function refreshAiHistory(soupId) {
  const box = $("#aiHistory");
  if (!box) return;
  const hist = store.aiHistory[soupId] || [];
  box.innerHTML = hist.length === 0
    ? `<div class="ai-empty">还没有提问记录。</div>`
    : hist.map((t) => `
      <div class="ai-turn">
        <div class="ai-q">${escapeHtml(t.q)}</div>
        <div class="ai-a ${classifyAnswer(t.a)}">${escapeHtml(t.a)}</div>
      </div>
    `).join("");
  box.scrollTop = box.scrollHeight;
}

function revealBase(e) {
  e.stopPropagation();
  const block = $("#baseBlock");
  const toggle = $("#revealToggle");
  if (!block) return;
  const collapsed = block.style.display === "none";
  block.style.display = collapsed ? "block" : "none";
  if (toggle) toggle.textContent = collapsed ? "▼ 收起汤底" : "▶ 点击展开汤底";
}
window.revealBase = revealBase;

function revealManual(e) {
  e.stopPropagation();
  const block = $("#manualBlock");
  const toggle = $("#manualToggle");
  if (!block) return;
  const collapsed = block.style.display === "none";
  block.style.display = collapsed ? "block" : "none";
  if (toggle) toggle.textContent = collapsed ? "▼ 收起主持人手册" : "▶ 点击展开主持人手册";
}
window.revealManual = revealManual;

function revealExtra(e) {
  e.stopPropagation();
  const block = $("#extraBlock");
  const toggle = $("#extraToggle");
  if (!block) return;
  const collapsed = block.style.display === "none";
  block.style.display = collapsed ? "block" : "none";
  if (toggle) toggle.textContent = collapsed ? "▼ 收起其他内容" : "▶ 点击展开其他内容";
}
window.revealExtra = revealExtra;

function closeModal(e) {
  if (e) e.stopPropagation();
  const root = $("#modalRoot");
  if (root) root.innerHTML = "";
  document.body.style.overflow = "";
}
window.closeModal = closeModal;

async function newRoomFromSoup(soupId) {
  if (!store.user) { toast("请先登录", "err"); location.hash = "#/auth"; return; }
  const { ok, data } = await API.post("/api/rooms", { soup_id: soupId, ai_enabled: true, ai_question_limit: 0, member_limit: 0 });
  if (!ok) { toast(data.error || "创建房间失败", "err"); return; }
  closeModal();
  location.hash = "#/room/" + data.code;
}
window.newRoomFromSoup = newRoomFromSoup;

// ---------- 登录注册 ----------
function renderAuth() {
  if (store.user) { location.hash = "#/"; return; }
  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml()}
      <div class="container-sm">
        <div class="form-card">
          <div class="logo-icon">🍲</div>
          <h2>海龟汤馆</h2>
          <p class="sub">登录后即可创建房间、向 AI 提问</p>
          <div class="form-tabs">
            <button class="form-tab active" id="tabLogin" onclick="switchAuthTab('login')">登录</button>
            <button class="form-tab" id="tabRegister" onclick="switchAuthTab('register')">注册</button>
          </div>
          <div id="authForm"></div>
        </div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;
  switchAuthTab("login");
}

let _authMode = "login";
let _regToken = "";
let _regCountdown = 0;
let _regTimer = null;
window.switchAuthTab = (mode) => {
  _authMode = mode;
  const tabLogin = $("#tabLogin");
  const tabRegister = $("#tabRegister");
  if (tabLogin) tabLogin.classList.toggle("active", mode === "login");
  if (tabRegister) tabRegister.classList.toggle("active", mode === "register");
  const f = $("#authForm");
  if (!f) return;
  if (mode === "login") {
    f.innerHTML = `
      <div id="formMsg"></div>
      <div class="field">
        <label>用户名或邮箱</label>
        <input class="input" id="loginAccount" placeholder="输入用户名或邮箱" />
      </div>
      <div class="field">
        <label>密码</label>
        <input class="input" id="loginPassword" type="password" placeholder="至少 6 位" onkeydown="if(event.key==='Enter')doLogin()" />
      </div>
      <button class="btn btn-primary" style="width:100%" onclick="doLogin()">登录</button>
    `;
  } else if (mode === "register") {
    f.innerHTML = `
      <div id="formMsg"></div>
      <div class="field">
        <label>邮箱</label>
        <div style="display:flex;gap:8px">
          <input class="input" id="regEmail" type="email" placeholder="用于接收验证码" style="flex:1" onkeydown="if(event.key==='Enter')doSendCode()" />
          <button class="btn btn-secondary" id="btnSendCode" style="flex:0 0 auto;min-width:auto;padding:0 16px" onclick="doSendCode()">获取验证码</button>
        </div>
      </div>
      <div class="field">
        <label>验证码</label>
        <input class="input" id="regCode" placeholder="6 位数字验证码" maxlength="6" onkeydown="if(event.key==='Enter')doRegister()" />
      </div>
      <div class="field">
        <label>用户名</label>
        <input class="input" id="regUsername" placeholder="中英文/数字/下划线，2-32 位" onkeydown="if(event.key==='Enter')doRegister()" />
      </div>
      <div class="field">
        <label>密码</label>
        <input class="input" id="regPassword" type="password" placeholder="至少 8 位" onkeydown="if(event.key==='Enter')doRegister()" />
      </div>
      <button class="btn btn-primary" style="width:100%" onclick="doRegister()">注册并登录</button>
      <p class="reg-hint">首个注册的账号将自动成为管理员</p>
    `;
    _regToken = "";
    _regCountdown = 0;
    if (_regTimer) { clearInterval(_regTimer); _regTimer = null; }
  }
};

function setFormMsg(msg, type = "err") {
  const m = $("#formMsg");
  if (!m) return;
  m.innerHTML = msg ? `<div class="form-${type === "err" ? "error" : "success"}">${escapeHtml(msg)}</div>` : "";
}

window.doSendCode = async () => {
  const email = ($("#regEmail")?.value || "").trim();
  if (!email) { setFormMsg("请填写邮箱"); return; }
  const btn = $("#btnSendCode");
  if (btn) { btn.disabled = true; }
  const { ok, data } = await API.post("/api/auth/send-code", { email });
  if (!ok) {
    setFormMsg(data.error || "发送失败");
    if (btn) btn.disabled = false;
    return;
  }
  // 开发模式：后端把验证码塞回 msg
  if (data.dev_mode) {
    setFormMsg(data.msg, "ok");
  } else {
    setFormMsg("验证码已发送，请查收邮箱（10 分钟内有效）", "ok");
  }
  _regToken = data.token || "";
  // 60 秒倒计时
  _regCountdown = 60;
  if (_regTimer) clearInterval(_regTimer);
  _regTimer = setInterval(() => {
    _regCountdown--;
    const b = $("#btnSendCode");
    if (!b) { clearInterval(_regTimer); _regTimer = null; return; }
    if (_regCountdown <= 0) {
      b.disabled = false;
      b.textContent = "重新获取";
      clearInterval(_regTimer);
      _regTimer = null;
    } else {
      b.textContent = `${_regCountdown}s 后重试`;
    }
  }, 1000);
};

window.doRegister = async () => {
  const email = ($("#regEmail")?.value || "").trim();
  const code = ($("#regCode")?.value || "").trim();
  const username = ($("#regUsername")?.value || "").trim();
  const password = $("#regPassword")?.value || "";
  if (!email || !code || !username || !password) { setFormMsg("请填写完整"); return; }
  if (!_regToken) { setFormMsg("请先获取验证码"); return; }
  const { ok, data } = await API.post("/api/auth/register", { email, code, token: _regToken, username, password });
  if (!ok) { setFormMsg(data.error || "注册失败"); return; }
  store.user = data.user;
  if (data.csrf_token) store.csrfToken = data.csrf_token;
  toast("注册成功", "ok");
  location.hash = "#/";
};

window.doLogin = async () => {
  const account = $("#loginAccount").value.trim();
  const password = $("#loginPassword").value;
  if (!account || !password) { setFormMsg("请填写完整"); return; }
  const { ok, data } = await API.post("/api/auth/login", { account, password });
  if (!ok) { setFormMsg(data.error || "登录失败"); return; }
  store.user = data.user;
  if (data.csrf_token) store.csrfToken = data.csrf_token;
  toast("登录成功", "ok");
  location.hash = "#/";
};

// ---------- 房间大厅 ----------
async function renderRooms() {
  if (!store.user) { toast("请先登录", "err"); location.hash = "#/auth"; return; }
  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("rooms")}
      <div class="container room-hall">
        <div class="hall-head">
          <div>
            <h2>多人房间</h2>
            <p style="margin:6px 0 0;color:var(--text-3);font-size:0.9rem">创建房间邀请好友，或输入房间号加入</p>
          </div>
          <div class="join-box">
            <input id="joinCode" placeholder="输入房间号加入" maxlength="6" />
            <button class="btn btn-secondary" style="min-width:auto;flex:0 0 auto;padding:0 18px" onclick="joinByCode()">加入</button>
          </div>
        </div>
        <div class="side-card" style="animation:fadeInUp 0.45s ease both">
          <h4>创建新房间</h4>
          <div class="field">
            <label>选择一碗汤（可不选，进入后再选）</label>
            <input class="input" id="newRoomSoup" placeholder="点击选择汤" readonly onclick="pickSoupForRoom()" />
          </div>
          <label style="display:flex;align-items:center;gap:8px;font-size:0.9rem;color:var(--text-2);margin-bottom:6px">
            <input type="checkbox" id="newRoomAi" checked /> 启用 AI 主持人
          </label>
          <p class="ai-hint" style="margin:0 0 14px;font-size:0.8rem">关闭则启用真人主持模式，房主担任主持人（可看汤底、回答问题）。</p>
          <div class="field">
            <label>AI 提问次数上限（0 = 无限）</label>
            <input class="input" id="newRoomAiLimit" type="number" min="0" max="999" value="0" placeholder="0 = 无限" />
          </div>
          <div class="field">
            <label>房间人数上限（0 = 无限）</label>
            <input class="input" id="newRoomMemberLimit" type="number" min="0" max="99" value="0" placeholder="0 = 无限" />
          </div>
          <button class="btn btn-primary" style="width:100%" onclick="createRoom()">创建房间</button>
          <p class="ai-hint" style="margin-top:10px">提示：AI 模式下房主可在房间侧栏绑定 AI Key（全员共用）；真人主持模式下房主直接回答玩家提问。</p>
        </div>
        <h3 class="section-title" style="margin-top:32px">进行中的房间</h3>
        <div id="roomList"><div class="empty"><div class="spinner"></div></div></div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;
  await loadRoomList();
}

async function loadRoomList() {
  const { ok, data } = await API.json("/api/rooms");
  const c = $("#roomList");
  if (!ok) { c.innerHTML = `<div class="empty"><p>加载失败</p></div>`; return; }
  const rooms = data.rooms || [];
  if (!rooms.length) {
    c.innerHTML = `<div class="empty"><div class="empty-icon">🎮</div><p>还没有进行中的房间，创建一个吧</p></div>`;
    return;
  }
  c.innerHTML = rooms.map((r) => `
    <div class="room-card">
      <div>
        <div class="code">${escapeHtml(r.code)}</div>
        <div class="info">房主：${escapeHtml(r.host?.username || "未知")} · ${r.ai_enabled ? "AI 已启用" : "无 AI"}${r.ai_enabled ? (r.has_host_key ? " · Key✓" : " · Key✗") : ""}${r.ai_question_limit > 0 ? ` · AI ${r.ai_question_count}/${r.ai_question_limit}` : ""}${r.member_limit > 0 ? ` · ${r.member_count}/${r.member_limit}人` : ""}</div>
      </div>
      <button class="btn btn-primary" style="min-width:auto;flex:0 0 auto;padding:8px 18px" onclick="location.hash='#/room/${r.code}'">进入</button>
    </div>
  `).join("");
}

let _pickedSoupId = null;
window.pickSoupForRoom = () => {
  if (!store.soups.length) { toast("汤数据未加载", "err"); return; }
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header">
        <div><h2 class="modal-title">选择一碗汤</h2></div>
        <button class="modal-close" onclick="closeModal(event)">✕</button>
      </div>
      <div class="modal-body">
        <div class="soup-picker" id="soupPickerList">
          ${store.soups.map((s) => `
            <div class="soup-pick-item" data-id="${s.id}" data-title="${escapeHtml(s.title)}">
              <div class="t">${escapeHtml(s.title)}</div>
              <div class="s">${escapeHtml(s.season)}${s.episode ? " · " + escapeHtml(s.episode) : ""}</div>
            </div>
          `).join("")}
        </div>
      </div>
    </div>
  `;
  $("#soupPickerList").querySelectorAll(".soup-pick-item").forEach((item) => {
    item.addEventListener("click", () => confirmPickSoup(+item.dataset.id, item.dataset.title));
  });
  document.body.style.overflow = "hidden";
};

window.confirmPickSoup = (id, title) => {
  _pickedSoupId = id;
  const input = $("#newRoomSoup");
  if (input) input.value = title;
  closeModal();
};

window.createRoom = async () => {
  const ai_enabled = $("#newRoomAi").checked;
  // 不再强制要求本机有 key：房主进入房间后可单独绑定 key 给房间全员共用
  const { ok, data } = await API.post("/api/rooms", {
    soup_id: _pickedSoupId || null,
    ai_enabled,
    ai_question_limit: parseInt($("#newRoomAiLimit")?.value) || 0,
    member_limit: parseInt($("#newRoomMemberLimit")?.value) || 0,
  });
  if (!ok) { toast(data.error || "创建失败", "err"); return; }
  // 若房主本机已配置 key，自动绑定到房间（方便房主，省一步）
  if (ai_enabled && KeyMgr.has()) {
    await API.post(`/api/rooms/${data.code}/ai-key`, {
      api_key: KeyMgr.get(),
      ...KeyMgr.getProviderPayload(),
    });
  }
  location.hash = "#/room/" + data.code;
};

window.joinByCode = () => {
  const code = ($("#joinCode").value || "").trim().toUpperCase();
  if (!code) { toast("请输入房间号", "err"); return; }
  location.hash = "#/room/" + code;
};

// ---------- 房间页 ----------
async function renderRoom(code) {
  if (!store.user) { toast("请先登录", "err"); location.hash = "#/auth"; return; }
  const { ok, data } = await API.json(`/api/rooms/${code}`);
  if (!ok) {
    // 普通房间不存在 → 尝试灵之残响房间（兼容后台开的测试房通过 #/room/XXX 进入）
    const lzcx = await API.json(`/api/lzcxroom/${code}`);
    if (lzcx.ok) {
      location.hash = "#/lzcxroom/" + code;
      return;
    }
    $("#app").innerHTML = `<div class="page">${headerHtml("rooms")}<div class="empty"><div class="empty-icon">🎮</div><p>${escapeHtml(data.error || "房间不存在")}</p><button class="btn btn-secondary" style="margin-top:16px" onclick="location.hash='#/rooms'">返回大厅</button></div></div>`;
    return;
  }
  const room = data.room;
  const soup = data.soup;
  const messages = data.messages || [];
  store.currentRoomCode = code;

  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("rooms")}
      <div class="container room-layout">
        <div class="chat-panel">
          <div class="chat-header">
            <div>
              <div class="chat-title">${escapeHtml(room.code)}</div>
              <div class="chat-code" id="chatCodeLine">${room.ai_enabled ? "AI 主持人" : "真人主持（房主）"}${room.ai_question_limit > 0 ? ` · AI提问 ${room.ai_question_count}/${room.ai_question_limit}` : ""}${room.member_limit > 0 ? ` · 人数 ${room.member_count}/${room.member_limit}` : ""}${room.state?.cleared ? " · 已通关" : ""}</div>
            </div>
            <button class="btn-icon" onclick="location.hash='#/rooms'" title="离开">←</button>
          </div>
          <div class="chat-body" id="chatBody"></div>
          ${room.status === "ended" ? `<div class="chat-ended-notice">房间已结束，无法继续发言</div>` : ""}
          <div class="chat-input">
            <input id="chatInput" placeholder="发言…" onkeydown="if(event.key==='Enter')sendChat()" ${room.status === "ended" ? "disabled" : ""} />
            <button class="btn btn-secondary" onclick="sendChat()" title="发送" ${room.status === "ended" ? "disabled" : ""}>💬</button>
            ${room.status !== "ended" && room.ai_enabled
              ? `<button class="btn btn-primary" onclick="sendAiQuestion()" title="向AI提问" ${room.ai_question_limit > 0 && room.ai_question_count >= room.ai_question_limit ? "disabled" : ""}>🤖</button>`
              : ""}
            ${room.status !== "ended" && !room.ai_enabled && room.host?.id !== store.user?.id
              ? `<button class="btn btn-primary" onclick="sendHostQuestion()" title="向主持人提问">🙋</button>`
              : ""}
          </div>
        </div>
        <div class="room-side">
          <div class="side-card">
            <h4>当前汤</h4>
            <div id="roomSoupBox">${
              soup
                ? `<div class="soup-mini"><div class="t">${escapeHtml(soup.title)}</div><div class="s">${escapeHtml(soup.season)}${soup.episode ? " · " + escapeHtml(soup.episode) : ""}</div><div class="surface">${escapeHtml(soup.surface || "")}</div></div>`
                : `<div class="no-soup">尚未选汤</div>`
            }</div>
            ${room.host?.id === store.user?.id ? `<button class="select-soup-btn" onclick="pickSoupForRoomUpdate('${escapeJs(room.code)}')">${soup ? "换一碗汤" : "选择一碗汤"}</button>` : ""}
          </div>
          <div class="side-card">
            <h4>玩法</h4>
            <p class="ai-hint" style="margin:0">
              ${room.ai_enabled
                ? "看汤面 → 向 AI 提是非题 → AI 只答「是/否/无关」→ 猜出汤底。"
                : "真人主持模式：向房主提问，房主回答「是/否/无关」。房主能看到汤底。"}
              ${room.host?.id === store.user?.id ? " 你是房主" + (room.ai_enabled ? "，可换汤、管理房间。" : "（主持人），可换汤、回答问题。") : ""}
            </p>
          </div>
          ${room.host?.id === store.user?.id && !room.ai_enabled && soup?.base ? `
          <div class="side-card host-panel">
            <h4>🎙 主持人面板</h4>
            <div class="host-base">
              <div class="host-base-label">汤底（仅你可见）</div>
              <div class="host-base-text">${escapeHtml(soup.base || "")}</div>
              ${soup.host_manual ? `<div class="host-base-label" style="margin-top:8px">主持人手册</div><div class="host-base-text">${escapeHtml(soup.host_manual)}</div>` : ""}
            </div>
            <div class="host-quick-answer">
              <div class="host-base-label">快捷回答</div>
              <div class="host-answer-btns">
                <button class="btn btn-secondary" onclick="hostAnswer('是')">是</button>
                <button class="btn btn-secondary" onclick="hostAnswer('否')">否</button>
                <button class="btn btn-secondary" onclick="hostAnswer('无关')">无关</button>
                <button class="btn btn-secondary" onclick="hostAnswer('恭喜你猜中了！')">🏆 猜中</button>
                <button class="btn btn-ghost" onclick="hostAnswerCustom()">自定义…</button>
              </div>
            </div>
          </div>` : ""}
          ${room.state?.cleared ? `
          <div class="side-card" id="clearStateBox"><p class="ai-hint" style="margin:0;color:var(--ok,#2c8)">🏆 已通关，真相大白！</p></div>` : ""}
          ${room.ai_enabled ? `
          <div class="side-card" id="aiKeyBox">
            <h4>AI Key（房间共用）</h4>
            ${room.has_host_key
              ? `<p class="ai-hint" style="margin:0 0 8px"><span style="color:var(--ok,#2c8)">✓ 房主已绑定 AI Key，全员可提问</span></p>`
              : `<p class="ai-hint" style="margin:0 0 8px"><span class="warn">⚠ 本房间尚未绑定 AI Key</span></p>`}
            ${room.host?.id === store.user?.id && room.status !== "ended" ? `
              <button class="btn btn-secondary" style="width:100%;margin-bottom:6px" onclick="bindHostKey('${escapeJs(room.code)}')">${room.has_host_key ? "更新 AI Key" : "绑定 AI Key"}</button>
              ${room.has_host_key ? `<button class="btn btn-ghost" style="width:100%" onclick="unbindHostKey('${escapeJs(room.code)}')">解绑</button>` : ""}
            ` : ""}
          </div>` : ""}
          ${room.host?.id === store.user?.id ? `
          <div class="side-card">
            <h4>房间管理</h4>
            <p class="ai-hint" style="margin:0 0 10px">房主可结束或解散当前房间。</p>
            ${room.status !== "ended" ? `<button class="btn btn-secondary" style="width:100%;margin-bottom:8px" onclick="closeRoom('${escapeJs(room.code)}')">结束房间（保留记录）</button>` : ""}
            <button class="btn btn-danger" style="width:100%" onclick="dissolveRoom('${escapeJs(room.code)}')">解散房间（永久删除）</button>
          </div>` : ""}
        </div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;

  // 渲染历史消息
  const body = $("#chatBody");
  body.innerHTML = messages.map(renderMsg).join("");
  body.scrollTop = body.scrollHeight;

  // 启动轮询：先彻底停掉旧 timer 并重置并发守卫，
  // 避免 renderRoom 全量重渲染与旧轮询竞争导致消息重复
  if (store.pollTimer) { clearInterval(store.pollTimer); store.pollTimer = null; }
  store.pollInFlight = false;
  store.pollLastId = messages.length ? messages[messages.length - 1].id : 0;
  connectRoom(code);
}

// 消息唯一 key：用内容 + 时间 + 类型计算，保证同一条消息在 DOM 里只出现一次
function msgKey(m) {
  const raw = (m.msg_type || "") + "|" + (m.username || "") + "|" + (m.content || "") + "|" + (m.created_at || "");
  // 简单字符串 hash → 36 进制短串，作为 DOM 属性 key
  let h = 0;
  for (let i = 0; i < raw.length; i++) {
    h = (h * 31 + raw.charCodeAt(i)) | 0;
  }
  return "m" + (h >>> 0).toString(36);
}

function renderMsg(m) {
  const mine = store.user && m.username === store.user.username;
  const cls = ["msg"];
  if (mine) cls.push("mine");
  if (m.msg_type) cls.push(m.msg_type);
  const prefix = m.msg_type === "ai_question" ? "🤔 " :
                 m.msg_type === "ai_answer" ? "🤖 " :
                 m.msg_type === "host_question" ? "🙋 " :
                 m.msg_type === "host_answer" ? "🎙 " :
                 m.msg_type === "system" ? "" : "";
  const who = m.msg_type === "system" ? "" : (m.username || "游客") + " · ";
  return `<div class="${cls.join(" ")}" data-key="${msgKey(m)}">
    <div class="meta">${who}${escapeHtml(m.created_at || "")}</div>
    <div class="bubble">${prefix}${escapeHtml(m.content)}</div>
  </div>`;
}

// 用轮询替代 WebSocket
function connectRoom(code) {
  toast("已加入房间 " + code, "ok");
  if (store.pollTimer) clearInterval(store.pollTimer);
  store.pollTimer = setInterval(() => pollMessages(code), 1500);
}

async function pollMessages(code) {
  if (location.hash !== "#/room/" + code) {
    if (store.pollTimer) { clearInterval(store.pollTimer); store.pollTimer = null; }
    return;
  }
  // 并发守卫：上一次轮询还没回来就跳过，避免并发拉到同一批消息重复渲染
  if (store.pollInFlight) return;
  store.pollInFlight = true;
  const since = store.pollLastId || 0;
  const { ok, data } = await API.json(`/api/rooms/${code}/messages?since=${since}`);
  store.pollInFlight = false;
  if (!ok || !data.messages) return;
  const body = $("#chatBody");
  if (!body) return;
  if (!data.messages.length) return; // 没有新消息，啥也别动

  // 记录用户是否在底部附近：只有用户本来就在看最新消息时，
  // 才自动滚到底部；否则保留用户当前浏览位置，避免被强制拉回底部。
  const nearBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 80;

  data.messages.forEach((m) => {
    // 按 content+时间算的 key 去重：DOM 里已存在相同 key 的消息不重复插入
    const key = msgKey(m);
    if (body.querySelector(`[data-key="${key}"]`)) {
      if (m.id && m.id > (store.pollLastId || 0)) store.pollLastId = m.id;
      return;
    }
    body.insertAdjacentHTML("beforeend", renderMsg(m));
    if (m.id && m.id > (store.pollLastId || 0)) store.pollLastId = m.id;
  });

  if (nearBottom) body.scrollTop = body.scrollHeight;
}

async function refreshRoomSoup(code) {
  const { ok, data } = await API.json(`/api/rooms/${code}`);
  if (!ok) return;
  const box = $("#roomSoupBox");
  const soup = data.soup;
  if (!box) return;
  box.innerHTML = soup
    ? `<div class="soup-mini"><div class="t">${escapeHtml(soup.title)}</div><div class="s">${escapeHtml(soup.season)}${soup.episode ? " · " + escapeHtml(soup.episode) : ""}</div><div class="surface">${escapeHtml(soup.surface || "")}</div></div>`
    : `<div class="no-soup">尚未选汤</div>`;
}

/**
 * 轻量刷新房间状态：只拉房间元数据，局部更新侧栏状态卡片，
 * 不重渲染聊天区、不打断用户输入/滚动。
 * 用于 hostAnswer / toggleNode / bindHostKey / unbindHostKey 等状态变更后。
 * 注意：关键节点列表对所有玩家隐藏，这里只更新状态行 + AI Key 卡片 + 通关提示。
 */
async function refreshRoomState(code) {
  const { ok, data } = await API.json(`/api/rooms/${code}`);
  if (!ok || !data.room) return;
  const room = data.room;
  const state = room.state || {};
  // 更新顶部 chat-code 状态行（含通关标记，所有人可见）
  const codeEl = $("#chatCodeLine");
  if (codeEl) {
    codeEl.textContent = `${room.ai_enabled ? "AI 主持人" : "真人主持（房主）"}${room.ai_question_limit > 0 ? ` · AI提问 ${room.ai_question_count}/${room.ai_question_limit}` : ""}${room.member_limit > 0 ? ` · 人数 ${room.member_count}/${room.member_limit}` : ""}${state.cleared ? " · 已通关" : ""}`;
  }
  // 通关提示卡片：未通关时移除，通关时创建（节点列表对所有人隐藏）
  const oldClear = $("#clearStateBox");
  if (state.cleared) {
    const html = `<p class="ai-hint" style="margin:0;color:var(--ok,#2c8)">🏆 已通关，真相大白！</p>`;
    if (oldClear) {
      oldClear.innerHTML = html;
    } else {
      // 不存在则在 aiKeyBox 前插入
      const keyBox2 = $("#aiKeyBox");
      const wrap = document.createElement("div");
      wrap.className = "side-card";
      wrap.id = "clearStateBox";
      wrap.innerHTML = html;
      if (keyBox2) keyBox2.parentNode.insertBefore(wrap, keyBox2);
    }
  } else if (oldClear) {
    oldClear.remove();
  }
  // 更新 AI Key 卡片
  const keyBox = $("#aiKeyBox");
  if (keyBox && room.ai_enabled) {
    keyBox.innerHTML = `
      <h4>AI Key（房间共用）</h4>
      ${room.has_host_key
        ? `<p class="ai-hint" style="margin:0 0 8px"><span style="color:var(--ok,#2c8)">✓ 房主已绑定 AI Key，全员可提问</span></p>`
        : `<p class="ai-hint" style="margin:0 0 8px"><span class="warn">⚠ 本房间尚未绑定 AI Key</span></p>`}
      ${room.host?.id === store.user?.id && room.status !== "ended" ? `
        <button class="btn btn-secondary" style="width:100%;margin-bottom:6px" onclick="bindHostKey('${escapeJs(room.code)}')">${room.has_host_key ? "更新 AI Key" : "绑定 AI Key"}</button>
        ${room.has_host_key ? `<button class="btn btn-ghost" style="width:100%" onclick="unbindHostKey('${escapeJs(room.code)}')">解绑</button>` : ""}
      ` : ""}
    `;
  }
}

// 聊天发送
window.sendChat = async () => {
  const input = $("#chatInput");
  if (!input) return;
  const content = input.value.trim();
  if (!content) return;
  const code = store.currentRoomCode;
  if (!code) { toast("未在房间内", "err"); return; }
  input.value = "";
  const { ok, data } = await API.post(`/api/rooms/${code}/messages`, { content });
  if (!ok) toast(data.error || "发送失败", "err");
};

// 房间内向 AI 提问
window.sendAiQuestion = async () => {
  const input = $("#chatInput");
  if (!input) return;
  const content = input.value.trim();
  if (!content) return;
  const code = store.currentRoomCode;
  if (!code) { toast("未在房间内", "err"); return; }
  input.value = "";
  // 不传 api_key：后端优先用房间绑定的房主 key（全员共用）
  const { ok, data } = await API.post(`/api/rooms/${code}/ai-question`, { content });
  if (!ok) {
    if (data.code === "missing_key") {
      toast("本房间未绑定 AI Key，请房主在房间侧栏绑定", "err");
    } else {
      toast(data.error || "提问失败", "err");
    }
    return;
  }
  if (data.error) toast(data.error, "err");
};

// 玩家向主持人提问（真人主持模式）
window.sendHostQuestion = async () => {
  const input = $("#chatInput");
  if (!input) return;
  const content = input.value.trim();
  if (!content) return;
  const code = store.currentRoomCode;
  if (!code) { toast("未在房间内", "err"); return; }
  input.value = "";
  const { ok, data } = await API.post(`/api/rooms/${code}/host-question`, { content });
  if (!ok) { toast(data.error || "提问失败", "err"); return; }
};

// 房主回答（真人主持模式）
window.hostAnswer = async (answer) => {
  const code = store.currentRoomCode;
  if (!code) return;
  const { ok, data } = await API.post(`/api/rooms/${code}/host-answer`, { answer });
  if (!ok) { toast(data.error || "回答失败", "err"); return; }
  if (data.cleared) toast("🏆 通关！", "ok");
  refreshRoomState(code);
};

window.hostAnswerCustom = async () => {
  const answer = prompt("请输入你的回答：");
  if (!answer) return;
  await hostAnswer(answer.trim());
};

// 房主手动标记/取消标记关键节点（真人主持模式）
window.toggleNode = async (nodeName, hit) => {
  const code = store.currentRoomCode;
  if (!code) return;
  const { ok, data } = await API.post(`/api/rooms/${code}/hit-node`, { node: nodeName, hit });
  if (!ok) { toast(data.error || "操作失败", "err"); return; }
  if (data.cleared) toast("🏆 通关！", "ok");
  refreshRoomState(code);
};

// 房主绑定 AI Key 到房间（加密存后端，房间全员共用）
window.bindHostKey = async (code) => {
  // 优先用本机已配置的 key；没有则弹框让房主输入
  let key = KeyMgr.get();
  let cfg = KeyMgr.getConfig();
  if (!key) {
    key = prompt("请输入 DeepSeek API Key（sk-...）：\n绑定后房间全员共用此 Key，无需各自配置。");
    if (!key) return;
    key = key.trim();
    cfg = {};
  }
  const { ok, data } = await API.post(`/api/rooms/${code}/ai-key`, {
    api_key: key,
    provider: cfg.provider || "deepseek",
    base_url: cfg.baseUrl || "",
    model: cfg.model || "",
  });
  if (!ok) { toast(data.error || "绑定失败", "err"); return; }
  toast("AI Key 已绑定，房间全员可共用", "ok");
  refreshRoomState(code);
};

window.unbindHostKey = async (code) => {
  if (!confirm("确认解绑房间 AI Key？\n解绑后房间内任何人都无法向 AI 提问。")) return;
  const { ok, data } = await API.post(`/api/rooms/${code}/ai-key`, { api_key: "" });
  if (!ok) { toast(data.error || "解绑失败", "err"); return; }
  toast("已解绑", "ok");
  refreshRoomState(code);
};

// 房主结束房间（软关闭，保留记录，可恢复）
window.closeRoom = async (code) => {
  if (!confirm("确认结束房间？\n结束后无法继续发言，但房间记录会保留。")) return;
  const { ok, data } = await API.del(`/api/rooms/${code}`);
  if (!ok) { toast(data.error || "结束失败", "err"); return; }
  toast("房间已结束", "ok");
  location.hash = "#/rooms";
  roomsList();
};

// 房主解散房间（硬删除，永久清除房间与所有消息，不可恢复）
window.dissolveRoom = async (code) => {
  if (!confirm("⚠️ 确认解散房间？\n\n房间及所有消息将被永久删除，不可恢复！")) return;
  if (!confirm("再次确认：此操作无法撤销，确定要解散吗？")) return;
  const { ok, data } = await API.post(`/api/rooms/${code}/dissolve`, {});
  if (!ok) { toast(data.error || "解散失败", "err"); return; }
  toast("房间已解散", "ok");
  location.hash = "#/rooms";
  roomsList();
};

window.pickSoupForRoomUpdate = (code) => {
  if (!store.soups.length) { toast("汤数据未加载", "err"); return; }
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header">
        <div><h2 class="modal-title">换一碗汤</h2></div>
        <button class="modal-close" onclick="closeModal(event)">✕</button>
      </div>
      <div class="modal-body">
        <div class="soup-picker" id="soupUpdateList" data-code="${escapeHtml(code)}">
          ${store.soups.map((s) => `
            <div class="soup-pick-item" data-id="${s.id}">
              <div class="t">${escapeHtml(s.title)}</div>
              <div class="s">${escapeHtml(s.season)}${s.episode ? " · " + escapeHtml(s.episode) : ""}</div>
            </div>
          `).join("")}
        </div>
      </div>
    </div>
  `;
  const list = $("#soupUpdateList");
  const roomCode = list.dataset.code;
  list.querySelectorAll(".soup-pick-item").forEach((item) => {
    item.addEventListener("click", () => updateRoomSoup(roomCode, +item.dataset.id));
  });
  document.body.style.overflow = "hidden";
};

window.updateRoomSoup = async (code, soupId) => {
  const { ok, data } = await API.post(`/api/rooms/${code}/select-soup`, { soup_id: soupId });
  if (!ok) { toast(data.error || "换汤失败", "err"); return; }
  closeModal();
  await refreshRoomSoup(code);
  toast("已换汤", "ok");
};

// ---------- 个人中心 ----------
async function renderProfile() {
  if (!store.user) { location.hash = "#/auth"; return; }
  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("profile")}
      <div class="container">
        <div class="profile-header">
          <div class="avatar">${escapeHtml(store.user.username.slice(0, 1).toUpperCase())}</div>
          <div class="info">
            <h2>${escapeHtml(store.user.username)}</h2>
            <p>${escapeHtml(store.user.email)} · 账号ID #${store.user.id}</p>
          </div>
        </div>
        <div class="profile-grid">
          <div class="profile-card">
            <h3>账号</h3>
            <div class="profile-stat"><span>用户名</span><span class="v">${escapeHtml(store.user.username)}</span></div>
            <div class="profile-stat"><span>邮箱</span><span class="v">${escapeHtml(store.user.email)}</span></div>
            <div class="profile-stat"><span>账号ID</span><span class="v">#${store.user.id}</span></div>
            <button class="btn btn-danger" style="margin-top:16px;width:100%" onclick="doLogout()">退出登录</button>
          </div>
          <div class="profile-card">
            <h3>AI 主持人</h3>
            <div class="profile-stat"><span>DeepSeek Key</span><span class="v">${KeyMgr.has() ? "已配置" : "未配置"}</span></div>
            <button class="btn btn-secondary" style="margin-top:16px;width:100%" onclick="openSettings()">配置 Key</button>
          </div>
          <div class="profile-card">
            <h3>关注</h3>
            <div id="followingList"><div class="spinner" style="margin:8px auto"></div></div>
          </div>
          <div class="profile-card">
            <h3>粉丝</h3>
            <div id="followersList"><div class="spinner" style="margin:8px auto"></div></div>
          </div>
          <div class="profile-card my-submissions-card">
            <h3>我的投稿</h3>
            <div id="mySubmissions"><div class="spinner" style="margin:8px auto"></div></div>
          </div>
        </div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;
  loadFollowList("following");
  loadFollowList("followers");
  loadMySubmissions();
}

async function loadFollowList(type) {
  const { ok, data } = await API.json(`/api/follow/${type}`);
  const el = $(`#${type}List`);
  if (!el) return;
  if (!ok || !data.list.length) { el.innerHTML = `<p style="color:var(--text-3);margin:4px 0">暂无${type === "following" ? "关注" : "粉丝"}</p>`; return; }
  el.innerHTML = data.list.map(u => `
    <div class="profile-stat"><span>${escapeHtml(u.username)}</span><span class="v">${type === "followers" && u.mutual ? "互关" : ""}</span></div>
  `).join("");
}

async function loadMySubmissions() {
  const { ok, data } = await API.json("/api/soups/my");
  const el = $("#mySubmissions");
  if (!el) return;
  if (!ok || !data.soups.length) {
    el.innerHTML = `<p style="color:var(--text-3);margin:4px 0">还没有投稿。<a href="#/square" style="color:var(--accent)">去写第一碗</a></p>`;
    return;
  }
  const statusMap = {
    pending: { label: "待审核", cls: "pending" },
    approved: { label: "已通过", cls: "approved" },
    rejected: { label: "已拒绝", cls: "rejected" },
  };
  el.innerHTML = data.soups.map((s) => {
    const st = statusMap[s.status] || { label: s.status, cls: "" };
    return `<div class="submission-item">
      <div class="submission-head">
        <a href="#/soup/${s.id}" class="submission-title">${escapeHtml(s.title)}</a>
        <span class="status-badge ${st.cls}">${st.label}</span>
      </div>
      ${s.status === "rejected" && s.reject_reason ? `<div class="reject-reason">拒绝原因：${escapeHtml(s.reject_reason)}</div>` : ""}
      <div class="submission-meta">${escapeHtml(s.season || "自制")}${s.episode ? " · " + escapeHtml(s.episode) : ""} · ${escapeHtml((s.created_at || "").slice(0, 10))}</div>
      <div class="submission-actions">
        <button class="admin-act-btn" onclick="openSoupEditor(${s.id})">${s.status === "rejected" ? "编辑后重投" : "编辑"}</button>
        <button class="admin-act-btn danger" onclick="deleteMySoup(${s.id})">删除</button>
      </div>
    </div>`;
  }).join("");
}

window.deleteMySoup = async (id) => {
  if (!confirm("确认删除这碗汤？此操作不可撤销")) return;
  const { ok, data } = await API.del(`/api/soups/${id}`);
  if (!ok) { toast(data.error || "删除失败", "err"); return; }
  toast("已删除", "ok");
  loadMySubmissions();
};

window.doLogout = async () => {
  await API.post("/api/auth/logout", {});
  store.user = null;
  toast("已退出", "ok");
  location.hash = "#/";
};

// ---------- 设置弹窗（Key 管理） ----------
function openSettings() {
  const root = $("#modalRoot");
  if (!root) return;
  const has = KeyMgr.has();
  const cfg = KeyMgr.getConfig();
  root.innerHTML = `
    <div class="overlay open" onclick="closeSettings(event)"></div>
    <div class="modal open">
      <div class="modal-header">
        <div><h2 class="modal-title">AI 设置</h2></div>
        <button class="modal-close" onclick="closeSettings(event)">✕</button>
      </div>
      <div class="modal-body">
        <div class="warning-box">
          <strong>⚠ 安全提示</strong>
          Key 仅保存在你的浏览器 localStorage 中，每次提问会随请求发到后端并透传给 AI 服务。
          请勿在公共电脑上保存；后端不存储、不记录你的 Key。
        </div>
        <div class="settings-row">
          <span class="settings-label">当前状态</span>
          <span class="settings-status ${has ? "ok" : "no"}">${has ? "已配置" : "未配置"}</span>
        </div>
        <div class="field" style="margin-top:16px">
          <label>AI 提供商</label>
          <select class="input" id="aiProvider" onchange="onProviderChange()">
            <option value="deepseek" ${cfg.provider === "deepseek" ? "selected" : ""}>DeepSeek</option>
            <option value="openai" ${cfg.provider === "openai" ? "selected" : ""}>OpenAI</option>
            <option value="custom" ${cfg.provider === "custom" ? "selected" : ""}>自定义（兼容 OpenAI 格式）</option>
          </select>
        </div>
        <div class="field" id="customUrlField" style="display:${cfg.provider === "custom" ? "block" : "none"}">
          <label>API 地址</label>
          <input class="input mono" id="aiBaseUrl" placeholder="https://your-api.com/v1" value="${escapeHtml(cfg.baseUrl || "")}" />
        </div>
        <div class="field">
          <label>模型名</label>
          <input class="input mono" id="aiModel" placeholder="deepseek-v4-flash" value="${escapeHtml(cfg.model || "")}" />
        </div>
        <div class="field">
          <label>API Key</label>
          <input class="input mono" id="apiKeyInput" type="password" placeholder="sk-..." value="${has ? escapeHtml(KeyMgr.get()) : ""}" />
        </div>
        <div id="testResult"></div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-secondary" onclick="testKey()" id="testBtn">测试连接</button>
        <button class="btn btn-secondary" onclick="clearKey()">清空</button>
        <button class="btn btn-primary" onclick="saveKey()">保存</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
}
window.openSettings = openSettings;

function closeSettings(e) {
  if (e) e.stopPropagation();
  const root = $("#modalRoot");
  if (root) root.innerHTML = "";
  document.body.style.overflow = "";
  // 局部刷新 header 的 Key 状态，不重渲染整页
  const btn = document.querySelector(".header .btn-icon");
  if (btn) {
    btn.classList.toggle("has-key", KeyMgr.has());
  }
  // 如果在首页，刷新统计栏
  if ((location.hash.replace(/^#/, "") === "/" || location.hash === "") && typeof renderStats === "function") {
    renderStats();
  }
}
window.closeSettings = closeSettings;

window.onProviderChange = () => {
  const p = $("#aiProvider").value;
  const f = $("#customUrlField");
  if (f) f.style.display = p === "custom" ? "block" : "none";
  const m = $("#aiModel");
  if (p === "deepseek" && !m.value) m.placeholder = "deepseek-v4-flash";
  else if (p === "openai" && !m.value) m.placeholder = "gpt-4o-mini";
  else m.placeholder = "model-name";
};

window.saveKey = () => {
  const v = $("#apiKeyInput").value.trim();
  if (!v) { toast("Key 不能为空", "err"); return; }
  KeyMgr.set(v);
  const provider = $("#aiProvider").value;
  const baseUrl = $("#aiBaseUrl")?.value.trim() || "";
  const model = $("#aiModel")?.value.trim() || "";
  KeyMgr.setConfig({ provider, baseUrl, model });
  toast("已保存", "ok");
  closeSettings();
};

window.clearKey = () => {
  KeyMgr.set("");
  toast("已清空", "ok");
  $("#apiKeyInput").value = "";
  closeSettings();
};

window.testKey = async () => {
  const v = $("#apiKeyInput").value.trim();
  if (!v) { toast("请先填写 Key", "err"); return; }
  const btn = $("#testBtn");
  btn.disabled = true;
  btn.innerHTML = `<span class="spinner sm"></span> 测试中…`;
  const res = await KeyMgr.test(v);
  btn.disabled = false;
  btn.textContent = "测试连接";
  const box = $("#testResult");
  box.innerHTML = `<div class="form-${res.ok ? "success" : "error"}">${escapeHtml(res.msg)}</div>`;
  if (res.ok) {
    // 测试通过则保存
    KeyMgr.set(v);
  }
};

// ---------- 管理员后台 ----------
const AdminAPI = {
  async get(path) { return API.json(path); },
  async post(path, body) { return API.post(path, body); },
  async put(path, body) { return API.put(path, body); },
  async del(path) { return API.del(path); },
};

function renderAdmin(hash) {
  if (!store.user) { toast("请先登录", "err"); location.hash = "#/auth"; return; }
  if (!store.user.is_admin) { toast("无管理员权限", "err"); location.hash = "#/"; return; }

  const section = hash.replace(/^\/admin\/?/, "") || "dashboard";
  const sections = [
    { id: "dashboard", label: "📊 仪表盘" },
    { id: "analytics", label: "📈 数据分析" },
    { id: "users", label: "👤 用户管理" },
    { id: "soups", label: "🍲 汤管理" },
    { id: "rooms", label: "🎮 房间管理" },
    { id: "settings", label: "⚙️ 系统设置" },
    { id: "logs", label: "📋 操作日志" },
    { id: "system", label: "🖥️ 系统信息" },
  ];

  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("admin")}
      <div class="admin-layout container">
        <aside class="admin-sidebar">
          ${sections.map(s => `<a href="#/admin/${s.id}" class="admin-nav-item ${section === s.id ? "active" : ""}">${s.label}</a>`).join("")}
        </aside>
        <main class="admin-main" id="adminContent">
          <div class="admin-loading"><div class="spinner"></div></div>
        </main>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;

  if (section === "dashboard") adminDashboard();
  else if (section === "analytics") adminAnalytics();
  else if (section === "users") adminUsers();
  else if (section === "soups") adminSoups();
  else if (section === "rooms") adminRooms();
  else if (section === "settings") adminSettings();
  else if (section === "logs") adminLogs();
  else if (section === "system") adminSystem();
  else adminDashboard();
}

function fmtSize(bytes) {
  if (bytes < 1024) return bytes + " B";
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + " KB";
  return (bytes / 1048576).toFixed(2) + " MB";
}

async function adminAnalytics() {
  const c = $("#adminContent");
  if (c) c.innerHTML = `<div class="admin-loading"><div class="spinner"></div></div>`;
  const [trendsRes, aiRes, retentionRes, roomsRes] = await Promise.all([
    AdminAPI.get("/api/admin/stats/trends?days=30"),
    AdminAPI.get("/api/admin/stats/ai-usage?days=30"),
    AdminAPI.get("/api/admin/stats/retention"),
    AdminAPI.get("/api/admin/stats/rooms?days=30"),
  ]);
  if (!c) return;
  const trends = trendsRes.ok ? trendsRes.data : {};
  const ai = aiRes.ok ? aiRes.data : {};
  const ret = retentionRes.ok ? retentionRes.data : {};
  const rooms = roomsRes.ok ? roomsRes.data : {};

  const drawLine = (data, color) => {
    if (!data || !data.length) return '<p style="color:var(--text-3)">暂无数据</p>';
    const max = Math.max(...data.map(d => d.c), 1);
    const w = 100, h = 40;
    const pts = data.map((d, i) => `${(i / (data.length - 1 || 1)) * w},${h - (d.c / max) * h}`).join(' ');
    return `<svg viewBox="0 0 ${w} ${h}" style="width:100%;max-width:400px;height:80px"><polyline points="${pts}" fill="none" stroke="${color}" stroke-width="1.5" /></svg>`;
  };

  c.innerHTML = `
    <div class="admin-section">
      <h2 class="admin-title">📈 数据分析</h2>
      <div class="profile-grid">
        <div class="profile-card">
          <h3>汤题热度趋势（近30天）</h3>
          ${drawLine(trends.soups, '#e94560')}
          ${trends.top_soups ? `<h4 style="margin-top:8px">热门汤题</h4>${trends.top_soups.map(s => `<div class="profile-stat"><span>${escapeHtml(s.title)}</span><span class="v">${s.view_count}次浏览</span></div>`).join('')}` : ''}
        </div>
        <div class="profile-card">
          <h3>AI 使用量</h3>
          <div class="profile-stat"><span>总提问数</span><span class="v">${ai.total || 0}</span></div>
          <div class="profile-stat"><span>房间模式</span><span class="v">${ai.room_ai || 0}</span></div>
          <div class="profile-stat"><span>单人模式</span><span class="v">${ai.single_ai || 0}</span></div>
          ${drawLine(ai.daily, '#4caf50')}
        </div>
        <div class="profile-card">
          <h3>用户活跃度</h3>
          <div class="profile-stat"><span>总用户</span><span class="v">${ret.total_users || 0}</span></div>
          <div class="profile-stat"><span>DAU</span><span class="v">${ret.dau || 0}</span></div>
          <div class="profile-stat"><span>WAU</span><span class="v">${ret.wau || 0}</span></div>
          <div class="profile-stat"><span>MAU</span><span class="v">${ret.mau || 0}</span></div>
        </div>
        <div class="profile-card">
          <h3>房间趋势</h3>
          <div class="profile-stat"><span>总房间</span><span class="v">${rooms.total || 0}</span></div>
          <div class="profile-stat"><span>AI房间占比</span><span class="v">${rooms.ai_ratio || 0}%</span></div>
          ${drawLine(rooms.daily, '#ff9800')}
        </div>
      </div>
    </div>
  `;
}
window.adminAnalytics = adminAnalytics;

// ---- 仪表盘 ----
async function adminDashboard() {
  const { ok, data } = await AdminAPI.get("/api/admin/stats");
  const c = $("#adminContent");
  if (!ok) { c.innerHTML = `<div class="admin-error">加载失败</div>`; return; }

  const cards = [
    { label: "用户总数", value: data.users_total, sub: `今日 +${data.new_users_today}`, icon: "👤" },
    { label: "汤总数", value: data.soups_total, sub: "收录", icon: "🍲" },
    { label: "房间总数", value: data.rooms_total, sub: `进行中 ${data.rooms_playing} / 已结束 ${data.rooms_ended}`, icon: "🎮" },
    { label: "消息总数", value: data.messages_total, sub: `今日 +${data.messages_today}`, icon: "💬" },
    { label: "管理员", value: data.users_admin, sub: "人", icon: "🔑" },
    { label: "封禁用户", value: data.users_banned, sub: "人", icon: "🚫" },
    { label: "数据库大小", value: fmtSize(data.db_size || 0), sub: "SQLite", icon: "💾" },
    { label: "PHP 版本", value: data.php_version, sub: "", icon: "🐘" },
  ];

  c.innerHTML = `
    <div class="admin-section">
      <h2 class="admin-title">📊 仪表盘</h2>
      <div class="admin-stat-grid">
        ${cards.map(c => `
          <div class="admin-stat-card">
            <div class="admin-stat-icon">${c.icon}</div>
            <div class="admin-stat-info">
              <div class="admin-stat-value">${escapeHtml(String(c.value))}</div>
              <div class="admin-stat-label">${escapeHtml(c.label)}</div>
              ${c.sub ? `<div class="admin-stat-sub">${escapeHtml(c.sub)}</div>` : ""}
            </div>
          </div>
        `).join("")}
      </div>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">最近注册用户</h3>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>用户名</th><th>邮箱</th><th>管理员</th><th>注册时间</th></tr></thead>
        <tbody>
          ${(data.recent_users || []).map(u => `
            <tr>
              <td>${u.id}</td>
              <td>${escapeHtml(u.username)}${u.is_banned ? ' <span class="tag tag-danger">封禁</span>' : ''}</td>
              <td>${escapeHtml(u.email)}</td>
              <td>${u.is_admin ? '<span class="tag tag-success">管理员</span>' : '-'}</td>
              <td>${escapeHtml(u.created_at)}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">最近创建的房间</h3>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>房间号</th><th>房主</th><th>状态</th><th>创建时间</th></tr></thead>
        <tbody>
          ${(data.recent_rooms || []).map(r => `
            <tr>
              <td>${r.id}</td>
              <td><a href="${r.room_type === 'lzcx' ? '#/lzcxroom/' : '#/room/'}${escapeHtml(r.code)}">${escapeHtml(r.code)}</a></td>
              <td>${escapeHtml(r.host_name || '-')}</td>
              <td>${r.status === 'playing' ? '<span class="tag tag-success">进行中</span>' : '<span class="tag tag-muted">已结束</span>'}</td>
              <td>${escapeHtml(r.created_at)}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>
  `;
}

// ---- 用户管理 ----
async function adminUsers(page = 1) {
  const q = $("#adminSearch")?.value || "";
  const { ok, data } = await AdminAPI.get(`/api/admin/users?page=${page}&q=${encodeURIComponent(q)}`);
  const c = $("#adminContent");
  if (!ok) { c.innerHTML = `<div class="admin-error">${escapeHtml(data.error || "加载失败")}</div>`; return; }

  c.innerHTML = `
    <div class="admin-section">
      <div class="admin-toolbar">
        <h2 class="admin-title">👤 用户管理</h2>
        <div class="admin-toolbar-right">
          <input class="input admin-search" id="adminSearch" placeholder="搜索用户名/邮箱…" value="${escapeHtml(q)}" onkeydown="if(event.key==='Enter')adminUsers(1)" />
          <button class="btn btn-primary admin-btn-sm" onclick="adminUsers(1)">搜索</button>
          <button class="btn btn-secondary admin-btn-sm" onclick="adminUserCreateModal()">+ 创建用户</button>
        </div>
      </div>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>用户名</th><th>邮箱</th><th>角色</th><th>状态</th><th>注册时间</th><th>操作</th></tr></thead>
        <tbody>
          ${data.users.map(u => `
            <tr>
              <td>${u.id}</td>
              <td>${escapeHtml(u.username)}</td>
              <td>${escapeHtml(u.email)}</td>
              <td>${u.is_admin ? '<span class="tag tag-success">管理员</span>' : '普通'}</td>
              <td>${u.is_banned ? '<span class="tag tag-danger">封禁</span>' : '<span class="tag tag-success">正常</span>'}</td>
              <td>${escapeHtml(u.created_at)}</td>
              <td class="admin-actions">
                <button class="admin-act-btn" onclick="adminUserToggleAdmin(${u.id}, ${u.is_admin})">${u.is_admin ? '取消管理' : '设为管理'}</button>
                <button class="admin-act-btn" onclick="adminUserToggleBan(${u.id}, ${u.is_banned})">${u.is_banned ? '解封' : '封禁'}</button>
                <button class="admin-act-btn" onclick="adminUserResetPwdModal(${u.id}, '${escapeJs(u.username)}')">重置密码</button>
                ${u.id !== store.user.id ? `<button class="admin-act-btn danger" onclick="adminUserDelete(${u.id}, '${escapeJs(u.username)}')">删除</button>` : ''}
              </td>
            </tr>
          `).join("")}
        </tbody>
      </table>
      ${adminPagination(data.page, data.total_pages, "adminUsers")}
    </div>
  `;
  $("#adminSearch")?.addEventListener("input", () => {});
}
window.adminUsers = adminUsers;

window.adminUserCreateModal = () => {
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header"><div><h2 class="modal-title">创建用户</h2></div><button class="modal-close" onclick="closeModal(event)">✕</button></div>
      <div class="modal-body">
        <div class="field"><label>用户名</label><input class="input" id="cu_username" /></div>
        <div class="field"><label>邮箱</label><input class="input" id="cu_email" type="email" /></div>
        <div class="field"><label>密码（至少6位）</label><input class="input" id="cu_password" type="password" /></div>
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="cu_is_admin" /> 设为管理员</label>
      </div>
      <div class="modal-actions">
        <button class="btn btn-ghost" onclick="closeModal(event)">取消</button>
        <button class="btn btn-primary" onclick="adminUserCreateDo()">创建</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
};

window.adminUserCreateDo = async () => {
  const username = $("#cu_username").value.trim();
  const email = $("#cu_email").value.trim();
  const password = $("#cu_password").value;
  const is_admin = $("#cu_is_admin").checked;
  if (!username || !email || password.length < 6) { toast("请填写完整，密码至少6位", "err"); return; }
  const { ok, data } = await AdminAPI.post("/api/admin/users", { username, email, password, is_admin });
  if (!ok) { toast(data.error || "创建失败", "err"); return; }
  toast("用户创建成功", "ok");
  closeModal();
  adminUsers(1);
};

window.adminUserToggleAdmin = async (id, current) => {
  const { ok, data } = await AdminAPI.put(`/api/admin/users/${id}`, { is_admin: !current });
  if (!ok) { toast(data.error || "操作失败", "err"); return; }
  toast("已更新", "ok");
  adminUsers();
};

window.adminUserToggleBan = async (id, current) => {
  if (!current) {
    const reason = prompt("封禁原因（可留空）：");
    if (reason === null) return;
    const { ok, data } = await AdminAPI.put(`/api/admin/users/${id}`, { is_banned: true, banned_reason: reason });
    if (!ok) { toast(data.error || "操作失败", "err"); return; }
    toast("已封禁", "ok");
  } else {
    const { ok, data } = await AdminAPI.put(`/api/admin/users/${id}`, { is_banned: false });
    if (!ok) { toast(data.error || "操作失败", "err"); return; }
    toast("已解封", "ok");
  }
  adminUsers();
};

window.adminUserResetPwdModal = (id, name) => {
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header"><div><h2 class="modal-title">重置密码 — ${escapeHtml(name)}</h2></div><button class="modal-close" onclick="closeModal(event)">✕</button></div>
      <div class="modal-body">
        <div class="field"><label>新密码（至少6位）</label><input class="input" id="rp_password" type="password" /></div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-ghost" onclick="closeModal(event)">取消</button>
        <button class="btn btn-primary" onclick="adminUserResetPwdDo(${id})">重置</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
};

window.adminUserResetPwdDo = async (id) => {
  const password = $("#rp_password").value;
  if (password.length < 6) { toast("密码至少6位", "err"); return; }
  const { ok, data } = await AdminAPI.put(`/api/admin/users/${id}/password`, { password });
  if (!ok) { toast(data.error || "操作失败", "err"); return; }
  toast("密码已重置", "ok");
  closeModal();
};

window.adminUserDelete = async (id, name) => {
  if (!confirm(`确认删除用户「${name}」？此操作不可撤销。`)) return;
  const { ok, data } = await AdminAPI.del(`/api/admin/users/${id}`);
  if (!ok) { toast(data.error || "删除失败", "err"); return; }
  toast("已删除", "ok");
  adminUsers();
};

// ---- 汤管理 ----
async function adminSoups(page = 1) {
  const q = $("#adminSearch")?.value || "";
  // 立即显示 loading，避免点击翻页时"无反应"的错觉
  const c = $("#adminContent");
  if (c) c.innerHTML = `<div class="admin-loading"><div class="spinner"></div></div>`;
  const { ok, data } = await AdminAPI.get(`/api/admin/soups?page=${page}&q=${encodeURIComponent(q)}`);
  if (!c) return;
  if (!ok) { c.innerHTML = `<div class="admin-error">${escapeHtml(data.error || "加载失败")}</div>`; return; }

  c.innerHTML = `
    <div class="admin-section">
      <div class="admin-toolbar">
        <h2 class="admin-title">🍲 汤管理</h2>
        <div class="admin-toolbar-right">
          <input class="input admin-search" id="adminSearch" placeholder="搜索标题/系列/文件名/汤面/汤底…" value="${escapeHtml(q)}" oninput="adminSoupsSearchDebounced()" onkeydown="if(event.key==='Enter')adminSoups(1)" />
          <button class="btn btn-primary admin-btn-sm" onclick="adminSoups(1)">搜索</button>
          <button class="btn btn-secondary admin-btn-sm" onclick="adminSoupEditModal()">+ 新建汤</button>
          <button class="btn btn-ghost admin-btn-sm" onclick="adminSoupsImport()">📁 批量导入</button>
          <button class="btn btn-ghost admin-btn-sm" onclick="adminSoupsReimport()" title="用最新解析规则重新解析所有汤（增量：更新已有/删除多余/导入新增）">🔄 重新解析</button>
          <button class="btn btn-ghost admin-btn-sm" onclick="adminSoupsRebuild()" title="强制清空所有汤再全量重新导入（换汤源后用这个）">💥 强制重建</button>
          <button class="btn btn-ghost admin-btn-sm" onclick="adminSoupsBroken()" title="检测汤面/汤底为空或疑似内容混入的汤">🩺 坏汤检测</button>
        </div>
      </div>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>标题</th><th>系列</th><th>集</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          ${data.soups.map(s => {
            const statusLabel = s.status === 'approved' ? '<span style="color:#4caf50">已通过</span>' : s.status === 'rejected' ? '<span style="color:#f44336">已拒绝</span>' : '<span style="color:#ff9800">待审核</span>';
            return `
            <tr>
              <td>${s.id}</td>
              <td>${escapeHtml(s.title)}</td>
              <td>${escapeHtml(s.season || '-')}</td>
              <td>${escapeHtml(s.episode || '-')}</td>
              <td>${statusLabel}</td>
              <td class="admin-actions">
                ${s.status === 'pending' ? `<button class="admin-act-btn" style="color:#4caf50" onclick="adminSoupApprove(${s.id})">通过</button><button class="admin-act-btn" style="color:#f44336" onclick="adminSoupReject(${s.id})">拒绝</button>` : ''}
                <button class="admin-act-btn" onclick="adminSoupEditModal(${s.id})">编辑</button>
                <button class="admin-act-btn danger" onclick="adminSoupDelete(${s.id}, '${escapeJs(s.title)}')">删除</button>
              </td>
            </tr>`;
          }).join("")}
        </tbody>
      </table>
      ${adminPagination(data.page, data.total_pages, "adminSoups")}
    </div>
  `;
}
window.adminSoups = adminSoups;

window.adminSoupEditModal = async (id) => {
  let soup = { title: '', season: '', episode: '', surface: '', base: '', host_manual: '', extra: '', filename: '' };
  if (id) {
    // 用单条汤接口获取，避免只查第一页导致跨页汤找不到
    const { ok, data } = await API.json(`/api/soups/${id}`);
    if (ok && data) soup = { ...soup, ...data };
  }
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header"><div><h2 class="modal-title">${id ? '编辑汤' : '新建汤'}</h2></div><button class="modal-close" onclick="closeModal(event)">✕</button></div>
      <div class="modal-body">
        <div class="field"><label>标题</label><input class="input" id="es_title" value="${escapeHtml(soup.title)}" /></div>
        <div class="admin-row">
          <div class="field"><label>系列/季</label><input class="input" id="es_season" value="${escapeHtml(soup.season || '')}" /></div>
          <div class="field"><label>集</label><input class="input" id="es_episode" value="${escapeHtml(soup.episode || '')}" /></div>
        </div>
        <div class="field"><label>文件名（不含.md，留空自动生成）</label><input class="input" id="es_filename" value="${escapeHtml(soup.filename || '')}" /></div>
        <div class="field"><label>汤面<span class="field-hint">玩家可见的谜面</span></label><textarea class="input" id="es_surface" rows="4">${escapeHtml(soup.surface || '')}</textarea></div>
        <div class="field"><label>汤底<span class="field-hint">仅 AI 可读，不主动透露给玩家</span></label><textarea class="input" id="es_base" rows="5">${escapeHtml(soup.base || '')}</textarea></div>
        <div class="field"><label>主持人手册<span class="field-hint">特殊玩法指令（撒谎策略/回答格式/触发语句等），AI 必须遵守</span></label><textarea class="input" id="es_host_manual" rows="5" placeholder="如：伪人/隐藏主持人玩法、规则触发条件、回答格式约束等">${escapeHtml(soup.host_manual || '')}</textarea></div>
        <div class="field"><label>其他内容<span class="field-hint">故事梗概/怪谈解析/隐藏规则/收容物设定等补充内容，仅用于 AI 理解全貌</span></label><textarea class="input" id="es_extra" rows="4" placeholder="如：故事梗概、怪谈解析、隐藏规则等">${escapeHtml(soup.extra || '')}</textarea></div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-ghost" onclick="closeModal(event)">取消</button>
        <button class="btn btn-primary" onclick="adminSoupSave(${id || 0})">保存</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
};

window.adminSoupSave = async (id) => {
  const body = {
    title: $("#es_title").value.trim(),
    season: $("#es_season").value.trim(),
    episode: $("#es_episode").value.trim(),
    filename: $("#es_filename").value.trim(),
    surface: $("#es_surface").value,
    base: $("#es_base").value,
    host_manual: $("#es_host_manual").value,
    extra: $("#es_extra").value,
  };
  if (!body.title || !body.surface || !body.base) { toast("标题、汤面、汤底不能为空", "err"); return; }
  const { ok, data } = id
    ? await AdminAPI.put(`/api/admin/soups/${id}`, body)
    : await AdminAPI.post("/api/admin/soups", body);
  if (!ok) { toast(data.error || "保存失败", "err"); return; }
  toast("已保存", "ok");
  closeModal();
  adminSoups();
};

window.adminSoupDelete = async (id, title) => {
  if (!confirm(`确认删除「${title}」？`)) return;
  const { ok, data } = await AdminAPI.del(`/api/admin/soups/${id}`);
  if (!ok) { toast(data.error || "删除失败", "err"); return; }
  toast("已删除", "ok");
  adminSoups();
};

window.adminSoupApprove = async (id) => {
  const { ok, data } = await AdminAPI.post(`/api/admin/soups/${id}/approve`, {});
  if (!ok) { toast(data.error || "审核失败", "err"); return; }
  toast("审核通过", "ok");
  adminSoups();
};

window.adminSoupReject = async (id) => {
  const reason = prompt("请输入拒绝原因：");
  if (!reason || !reason.trim()) return;
  const { ok, data } = await AdminAPI.post(`/api/admin/soups/${id}/reject`, { reason: reason.trim() });
  if (!ok) { toast(data.error || "操作失败", "err"); return; }
  toast("已拒绝", "ok");
  adminSoups();
};

window.adminSoupsImport = async () => {
  if (!confirm("从汤源目录批量导入 MD 文件？已存在的会跳过。")) return;
  const { ok, data } = await AdminAPI.post("/api/admin/soups/import", {});
  if (!ok) { toast(data.error || "导入失败", "err"); return; }
  toast(data.msg || "导入完成", "ok");
  adminSoups();
};

window.adminSoupsReimport = async () => {
  if (!confirm("用最新解析规则重新解析所有汤？\n增量模式：更新已有汤、删除源文件不存在的、导入新增的。")) return;
  const { ok, data } = await AdminAPI.post("/api/admin/soups/reimport", {});
  if (!ok) { toast(data.error || "重新解析失败", "err"); return; }
  toast(data.msg || "已重新解析", "ok");
  adminSoups();
};

window.adminSoupsRebuild = async () => {
  if (!confirm("⚠️ 强制重建：删除数据库中所有汤，再从源目录全量重新导入。\n\n这会清空所有汤（包括手动新建的），确定继续？")) return;
  const { ok, data } = await AdminAPI.post("/api/admin/soups/rebuild", {});
  if (!ok) { toast(data.error || "强制重建失败", "err"); return; }
  toast(data.msg || "强制重建完成", "ok");
  adminSoups();
};

// 搜索防抖：输入时实时搜索（300ms 延迟）
let _adminSoupsSearchTimer = null;
window.adminSoupsSearchDebounced = () => {
  clearTimeout(_adminSoupsSearchTimer);
  _adminSoupsSearchTimer = setTimeout(() => adminSoups(1), 300);
};

window.adminSoupsBroken = async () => {
  const c = $("#adminContent");
  c.innerHTML = `<div class="admin-section"><div class="admin-toolbar"><h2 class="admin-title">🩺 坏汤检测</h2></div><div class="admin-loading">检测中…</div></div>`;
  const { ok, data } = await AdminAPI.get("/api/admin/soups/broken");
  if (!ok) { c.innerHTML = `<div class="admin-error">${escapeHtml(data.error || "检测失败")}</div>`; return; }

  const broken = data.broken || [];
  if (!broken.length) {
    c.innerHTML = `
      <div class="admin-section">
        <div class="admin-toolbar"><h2 class="admin-title">🩺 坏汤检测</h2></div>
        <div class="admin-empty">
          <p>✅ 全部 ${data.total} 碗汤均正常，未发现汤面/汤底为空或内容混入。</p>
          <button class="btn btn-ghost" onclick="adminSoups()">← 返回汤管理</button>
        </div>
      </div>`;
    return;
  }

  c.innerHTML = `
    <div class="admin-section">
      <div class="admin-toolbar">
        <h2 class="admin-title">🩺 坏汤检测</h2>
        <div class="admin-toolbar-right">
          <button class="btn btn-ghost admin-btn-sm" onclick="adminSoups()">← 返回汤管理</button>
          <button class="btn btn-primary admin-btn-sm" onclick="adminSoupsReimport()">🔄 重新解析后再测</button>
        </div>
      </div>
      <p class="admin-tip">共 ${data.total} 碗汤，发现 <strong>${broken.length}</strong> 碗需要修复。点击「编辑」可手动修正汤面/汤底/主持人手册/其他内容。</p>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>标题</th><th>系列/集</th><th>问题</th><th>字数(面/底/手册)</th><th>操作</th></tr></thead>
        <tbody>
          ${broken.map(s => `
            <tr>
              <td>${s.id}</td>
              <td>${escapeHtml(s.title)}</td>
              <td>${escapeHtml(s.season || '-')} ${escapeHtml(s.episode || '')}</td>
              <td><span class="admin-tag-warn">${s.issues.map(escapeHtml).join('；')}</span></td>
              <td>${s.surface_len} / ${s.base_len} / ${s.host_manual_len}</td>
              <td class="admin-actions">
                <button class="admin-act-btn" onclick="adminSoupEditModal(${s.id})">编辑</button>
                <a class="admin-act-btn" href="#/soup/${s.id}" target="_blank">预览</a>
              </td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>
  `;
};

// ---- 房间管理 ----
async function adminRooms(page = 1) {
  const q = $("#adminSearch")?.value || "";
  const status = $("#adminStatusFilter")?.value || "";
  const { ok, data } = await AdminAPI.get(`/api/admin/rooms?page=${page}&q=${encodeURIComponent(q)}&status=${encodeURIComponent(status)}`);
  const c = $("#adminContent");
  if (!ok) { c.innerHTML = `<div class="admin-error">${escapeHtml(data.error || "加载失败")}</div>`; return; }

  c.innerHTML = `
    <div class="admin-section">
      <div class="admin-toolbar">
        <h2 class="admin-title">🎮 房间管理</h2>
        <div class="admin-toolbar-right">
          <button class="btn btn-secondary admin-btn-sm" onclick="openLzcxTestRoomModal()">🌙 开灵之残响测试房</button>
          <input class="input admin-search" id="adminSearch" placeholder="搜索房间号/房主…" value="${escapeHtml(q)}" onkeydown="if(event.key==='Enter')adminRooms(1)" />
          <select class="input admin-select" id="adminStatusFilter" onchange="adminRooms(1)">
            <option value="">全部状态</option>
            <option value="playing" ${status === 'playing' ? 'selected' : ''}>进行中</option>
            <option value="ended" ${status === 'ended' ? 'selected' : ''}>已结束</option>
          </select>
          <button class="btn btn-primary admin-btn-sm" onclick="adminRooms(1)">搜索</button>
        </div>
      </div>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>房间号</th><th>类型</th><th>房主</th><th>汤</th><th>状态</th><th>AI</th><th>创建时间</th><th>操作</th></tr></thead>
        <tbody>
          ${data.rooms.map(r => `
            <tr>
              <td>${r.id}</td>
              <td><a href="${r.room_type === 'lzcx' ? '#/lzcxroom/' : '#/room/'}${escapeHtml(r.code)}">${escapeHtml(r.code)}</a></td>
              <td>${r.room_type === 'lzcx' ? '<span class="tag tag-info">灵之残响</span>' : '<span class="tag tag-muted">普通</span>'}</td>
              <td>${escapeHtml(r.host_name || '-')}</td>
              <td>${escapeHtml(r.soup_title || '-')}</td>
              <td>${r.status === 'playing' ? '<span class="tag tag-success">进行中</span>' : '<span class="tag tag-muted">已结束</span>'}</td>
              <td>${r.ai_enabled ? '✅' : '❌'}</td>
              <td>${escapeHtml(r.created_at)}</td>
              <td class="admin-actions">
                <button class="admin-act-btn" onclick="adminRoomToggleStatus(${r.id}, '${escapeJs(r.status)}')">${r.status === 'playing' ? '结束' : '恢复'}</button>
                <button class="admin-act-btn" onclick="adminRoomMessages(${r.id}, '${escapeJs(r.code)}')">消息</button>
                <button class="admin-act-btn danger" onclick="adminRoomDelete(${r.id}, '${escapeJs(r.code)}')">删除</button>
              </td>
            </tr>
          `).join("")}
        </tbody>
      </table>
      ${adminPagination(data.page, data.total_pages, "adminRooms")}
    </div>
  `;
}
window.adminRooms = adminRooms;

window.adminRoomToggleStatus = async (id, status) => {
  const newStatus = status === 'playing' ? 'ended' : 'playing';
  const { ok, data } = await AdminAPI.put(`/api/admin/rooms/${id}/status`, { status: newStatus });
  if (!ok) { toast(data.error || "操作失败", "err"); return; }
  toast("已更新", "ok");
  adminRooms();
};

window.adminRoomDelete = async (id, code) => {
  if (!confirm(`确认删除房间「${code}」？所有消息也会被删除。`)) return;
  const { ok, data } = await AdminAPI.del(`/api/admin/rooms/${id}`);
  if (!ok) { toast(data.error || "删除失败", "err"); return; }
  toast("已删除", "ok");
  adminRooms();
};

window.adminRoomMessages = async (roomId, code) => {
  const { ok, data } = await AdminAPI.get(`/api/admin/rooms/${roomId}/messages`);
  if (!ok) { toast("加载失败", "err"); return; }
  const root = $("#modalRoot");
  const msgs = data.messages || [];
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header"><div><h2 class="modal-title">房间 ${escapeHtml(code)} 消息</h2></div><button class="modal-close" onclick="closeModal(event)">✕</button></div>
      <div class="modal-body">
        ${msgs.length === 0 ? '<p class="admin-empty">暂无消息</p>' : `
          <table class="admin-table">
            <thead><tr><th>ID</th><th>用户</th><th>类型</th><th>内容</th><th>时间</th><th>操作</th></tr></thead>
            <tbody>
              ${msgs.map(m => `
                <tr>
                  <td>${m.id}</td>
                  <td>${escapeHtml(m.username || '系统')}</td>
                  <td>${escapeHtml(m.msg_type)}</td>
                  <td class="admin-msg-content">${escapeHtml(m.content)}</td>
                  <td>${escapeHtml(m.created_at)}</td>
                  <td><button class="admin-act-btn danger" onclick="adminMsgDelete(${m.id})">删除</button></td>
                </tr>
              `).join("")}
            </tbody>
          </table>
        `}
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
};

window.adminMsgDelete = async (id) => {
  const { ok, data } = await AdminAPI.del(`/api/admin/messages/${id}`);
  if (!ok) { toast(data.error || "删除失败", "err"); return; }
  toast("已删除", "ok");
  closeModal();
};

// ---- 系统设置 ----
async function adminSettings() {
  // 同时拉系统设置和邮件配置
  const [resSettings, resMail] = await Promise.all([
    AdminAPI.get("/api/admin/settings"),
    AdminAPI.get("/api/admin/settings/smtp"),
  ]);
  const c = $("#adminContent");
  if (!resSettings.ok) { c.innerHTML = `<div class="admin-error">加载失败</div>`; return; }

  const s = resSettings.data.settings || {};
  const config = resSettings.data.config || {};
  const mail = resMail.ok ? (resMail.data.mail || {}) : {};
  const curProvider = resMail.ok ? (resMail.data.provider || "smtp") : "smtp";
  const smtpPassHas = mail.mail_smtp_pass && mail.mail_smtp_pass.has_value;
  const resendKeyHas = mail.resend_api_key && mail.resend_api_key.has_value;

  const showSmtp = curProvider === "smtp" ? "" : "display:none";
  const showResend = curProvider === "resend" ? "" : "display:none";

  c.innerHTML = `
    <div class="admin-section">
      <h2 class="admin-title">⚙️ 系统设置</h2>
      <div class="admin-form">
        <div class="admin-form-row">
          <label>
            <input type="checkbox" id="set_allow_submit" ${s.allow_submit === '1' || config.ALLOW_SUBMIT ? 'checked' : ''} />
            允许用户投稿汤（投稿功能）
          </label>
        </div>
        <div class="admin-form-row">
          <label>
            <input type="checkbox" id="set_allow_register" ${s.allow_register === '1' || (config.ALLOW_REGISTER !== undefined ? config.ALLOW_REGISTER : true) ? 'checked' : ''} />
            允许公开注册（关闭后只能由管理员后台建号）
          </label>
        </div>
        <div class="admin-form-row">
          <label>房间消息保留条数（0=全部）</label>
          <input class="input" type="number" id="set_room_msg_limit" value="${s.room_msg_limit || config.ROOM_MSG_LIMIT || 200}" />
        </div>
        <button class="btn btn-primary" onclick="adminSettingsSave()">保存设置</button>
      </div>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">📧 邮件配置（注册验证码）</h3>
      <p class="admin-hint">SMTP 走 465/587 端口，云厂商封端口时切到 Resend（HTTP API，走 443，绕过封锁）。</p>
      <div class="admin-form">
        <div class="admin-form-row">
          <label>邮件服务商</label>
          <div class="provider-radio">
            <label><input type="radio" name="mail_provider" value="smtp" ${curProvider === 'smtp' ? 'checked' : ''} onchange="switchMailProvider('smtp')" /> SMTP（465/587）</label>
            <label><input type="radio" name="mail_provider" value="resend" ${curProvider === 'resend' ? 'checked' : ''} onchange="switchMailProvider('resend')" /> Resend HTTP API（443，推荐）</label>
          </div>
        </div>

        <div id="smtpBlock" style="${showSmtp}">
          <div class="admin-form-row">
            <label>SMTP 服务器</label>
            <input class="input" id="smtp_host" placeholder="smtp.qq.com / smtp.163.com / smtp.gmail.com" value="${escapeHtml(mail.mail_smtp_host || '')}" />
          </div>
          <div class="admin-form-row">
            <label>SMTP 端口</label>
            <input class="input" type="number" id="smtp_port" placeholder="465（SSL）/ 587（STARTTLS）" value="${escapeHtml(String(mail.mail_smtp_port || 465))}" />
          </div>
          <div class="admin-form-row">
            <label>SMTP 账号</label>
            <input class="input" id="smtp_user" placeholder="发件邮箱地址" value="${escapeHtml(mail.mail_smtp_user || '')}" />
          </div>
          <div class="admin-form-row">
            <label>SMTP 密码 / 授权码 ${smtpPassHas ? '<span class="smtp-pass-set">（已设置，留空不修改）</span>' : ''}</label>
            <input class="input" id="smtp_pass" type="password" placeholder="${smtpPassHas ? '已设置，留空不修改' : '邮箱授权码（不是登录密码）'}" />
          </div>
          <div class="admin-form-row">
            <label>发件邮箱（不填默认用 SMTP 账号）</label>
            <input class="input" id="smtp_from" placeholder="noreply@yourdomain.com" value="${escapeHtml(mail.mail_from || '')}" />
          </div>
          <div class="admin-form-row">
            <label>发件人名称</label>
            <input class="input" id="smtp_from_name" placeholder="海龟汤馆" value="${escapeHtml(mail.mail_from_name || '海龟汤馆')}" />
          </div>
        </div>

        <div id="resendBlock" style="${showResend}">
          <div class="admin-form-row">
            <label>Resend API Key ${resendKeyHas ? '<span class="smtp-pass-set">（已设置，留空不修改）</span>' : ''}</label>
            <input class="input" id="resend_api_key" type="password" placeholder="${resendKeyHas ? '已设置，留空不修改' : 're_xxx，在 https://resend.com/api-keys 创建'}" />
            <p class="admin-hint">注册 Resend 账号 → API Keys → Create API Key → 复制 re_xxx</p>
          </div>
          <div class="admin-form-row">
            <label>Resend 发件人</label>
            <input class="input" id="resend_from" placeholder='海龟汤馆 <onboarding@resend.dev>' value="${escapeHtml(mail.resend_from || '海龟汤馆 <onboarding@resend.dev>')}" />
            <p class="admin-hint">必须用 Resend 已验证的域名。没有域名时用 onboarding@resend.dev（仅能发到注册 Resend 的邮箱）。</p>
          </div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button class="btn btn-primary" onclick="adminMailSave()">💾 保存邮件配置</button>
          <button class="btn btn-secondary" onclick="adminSmtpTestModal()">📨 发送测试邮件</button>
        </div>
      </div>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">当前配置（只读）</h3>
      <table class="admin-table">
        <thead><tr><th>配置项</th><th>值</th></tr></thead>
        <tbody>
          ${Object.entries(config).map(([k, v]) => `<tr><td>${escapeHtml(k)}</td><td>${escapeHtml(String(v))}</td></tr>`).join("")}
        </tbody>
      </table>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">数据备份</h3>
      <p class="admin-hint">点击下载当前数据库完整备份（SQLite 文件）。</p>
      <a class="btn btn-secondary" href="/api/admin/backup" download>💾 下载数据库备份</a>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">🔧 运维工具</h3>
      <p class="admin-hint">一键拉取代码更新、清除缓存、压缩数据库等。</p>
      <a class="btn btn-primary" href="/tool.php" target="_blank">🔧 打开运维工具</a>
    </div>
  `;
}

window.switchMailProvider = (p) => {
  const smtp = $("#smtpBlock");
  const resend = $("#resendBlock");
  if (smtp) smtp.style.display = (p === "smtp") ? "" : "none";
  if (resend) resend.style.display = (p === "resend") ? "" : "none";
};

window.adminSettingsSave = async () => {
  const body = {
    allow_submit: $("#set_allow_submit").checked,
    allow_register: $("#set_allow_register").checked,
    room_msg_limit: parseInt($("#set_room_msg_limit").value) || 0,
  };
  const { ok, data } = await AdminAPI.put("/api/admin/settings", body);
  if (!ok) { toast(data.error || "保存失败", "err"); return; }
  toast("设置已保存", "ok");
};

window.adminMailSave = async () => {
  // 读取当前选中的 provider
  const provider = document.querySelector('input[name="mail_provider"]:checked')?.value || "smtp";
  const body = {
    mail_provider: provider,
    mail_smtp_host: $("#smtp_host")?.value.trim() || "",
    mail_smtp_port: parseInt($("#smtp_port")?.value) || 465,
    mail_smtp_user: $("#smtp_user")?.value.trim() || "",
    mail_smtp_pass: $("#smtp_pass")?.value || "",
    mail_from: $("#smtp_from")?.value.trim() || "",
    mail_from_name: $("#smtp_from_name")?.value.trim() || "海龟汤馆",
    resend_api_key: $("#resend_api_key")?.value || "",
    resend_from: $("#resend_from")?.value.trim() || "海龟汤馆 <onboarding@resend.dev>",
  };
  const { ok, data } = await AdminAPI.put("/api/admin/settings/smtp", body);
  if (!ok) { toast(data.error || "保存失败", "err"); return; }
  toast("邮件配置已保存", "ok");
  adminSettings();
};

window.adminSmtpTestModal = () => {
  const root = $("#modalRoot");
  if (!root) return;
  const provider = document.querySelector('input[name="mail_provider"]:checked')?.value || "smtp";
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header">
        <div><h2 class="modal-title">发送测试邮件（${escapeHtml(provider)}）</h2></div>
        <button class="modal-close" onclick="closeModal(event)">✕</button>
      </div>
      <div class="modal-body">
        <p class="admin-hint" style="margin-bottom:14px">使用当前已保存的邮件配置（provider: ${escapeHtml(provider)}），向指定邮箱发送一封测试邮件。</p>
        <div class="field">
          <label>收件邮箱</label>
          <input class="input" id="smtpTestTo" type="email" placeholder="输入收件邮箱地址" />
        </div>
        <button class="btn btn-primary" style="width:100%" onclick="adminSmtpTestDo()">发送测试邮件</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
};

window.adminSmtpTestDo = async () => {
  const to = ($("#smtpTestTo")?.value || "").trim();
  if (!to) { toast("请填写收件邮箱", "err"); return; }
  const { ok, data } = await AdminAPI.post("/api/admin/settings/smtp/test", { to });
  if (!ok) { toast(data.error || "发送失败", "err"); return; }
  toast(data.msg || "测试邮件已发送", "ok");
  closeModal();
};

// ---- 操作日志 ----
async function adminLogs(page = 1) {
  const { ok, data } = await AdminAPI.get(`/api/admin/logs?page=${page}`);
  const c = $("#adminContent");
  if (!ok) { c.innerHTML = `<div class="admin-error">加载失败</div>`; return; }

  c.innerHTML = `
    <div class="admin-section">
      <h2 class="admin-title">📋 操作日志</h2>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>操作人</th><th>动作</th><th>目标</th><th>详情</th><th>IP</th><th>时间</th></tr></thead>
        <tbody>
          ${data.logs.map(l => `
            <tr>
              <td>${l.id}</td>
              <td>${escapeHtml(l.admin_name || '-')}</td>
              <td><span class="tag tag-info">${escapeHtml(l.action)}</span></td>
              <td>${escapeHtml(l.target || '-')}</td>
              <td>${escapeHtml(l.detail || '-')}</td>
              <td>${escapeHtml(l.ip || '-')}</td>
              <td>${escapeHtml(l.created_at)}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
      ${adminPagination(data.page, data.total_pages, "adminLogs")}
    </div>
  `;
}
window.adminLogs = adminLogs;

// ---- 系统信息 ----
async function adminSystem() {
  const { ok, data } = await AdminAPI.get("/api/admin/system");
  const c = $("#adminContent");
  if (!ok) { c.innerHTML = `<div class="admin-error">加载失败</div>`; return; }

  c.innerHTML = `
    <div class="admin-section">
      <h2 class="admin-title">🖥️ 系统信息</h2>
      <div class="admin-stat-grid">
        <div class="admin-stat-card"><div class="admin-stat-icon">🐘</div><div><div class="admin-stat-value">${escapeHtml(data.php_version)}</div><div class="admin-stat-label">PHP 版本</div></div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">💾</div><div><div class="admin-stat-value">${fmtSize(data.db_size || 0)}</div><div class="admin-stat-label">数据库大小</div></div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">📂</div><div><div class="admin-stat-value">${data.disk_free ? fmtSize(data.disk_free) : '-'}</div><div class="admin-stat-label">磁盘剩余</div></div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">🕐</div><div><div class="admin-stat-value" style="font-size:1rem">${escapeHtml(data.server_time)}</div><div class="admin-stat-label">服务器时间</div></div></div>
      </div>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">数据表行数</h3>
      <table class="admin-table">
        <thead><tr><th>表名</th><th>行数</th></tr></thead>
        <tbody>
          ${Object.entries(data.table_sizes || {}).map(([t, n]) => `<tr><td>${escapeHtml(t)}</td><td>${n}</td></tr>`).join("")}
        </tbody>
      </table>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">PHP 扩展</h3>
      <table class="admin-table">
        <thead><tr><th>扩展</th><th>状态</th></tr></thead>
        <tbody>
          ${Object.entries(data.extensions || {}).map(([e, v]) => `<tr><td>${escapeHtml(e)}</td><td>${v ? '✅ 已加载' : '❌ 未加载'}</td></tr>`).join("")}
        </tbody>
      </table>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">PHP 配置</h3>
      <table class="admin-table">
        <thead><tr><th>配置项</th><th>值</th></tr></thead>
        <tbody>
          <tr><td>SAPI</td><td>${escapeHtml(data.php_sapi || '-')}</td></tr>
          <tr><td>操作系统</td><td>${escapeHtml(data.php_os || '-')}</td></tr>
          <tr><td>时区</td><td>${escapeHtml(data.timezone || '-')}</td></tr>
          <tr><td>最大上传</td><td>${escapeHtml(data.max_upload || '-')}</td></tr>
          <tr><td>最大 POST</td><td>${escapeHtml(data.max_post || '-')}</td></tr>
          <tr><td>内存限制</td><td>${escapeHtml(data.memory_limit || '-')}</td></tr>
          <tr><td>数据库路径</td><td>${escapeHtml(data.db_path || '-')}</td></tr>
          <tr><td>汤源目录</td><td>${escapeHtml(data.soups_dir || '-')} (${data.soups_dir_exists ? '存在' : '不存在'})</td></tr>
        </tbody>
      </table>
    </div>
  `;
}

// ---- 分页组件 ----
function adminPagination(page, totalPages, fnName) {
  if (totalPages <= 1) return '';
  let btns = [];
  if (page > 1) btns.push(`<button class="admin-page-btn" onclick="${fnName}(${page - 1})">上一页</button>`);
  const start = Math.max(1, page - 2);
  const end = Math.min(totalPages, page + 2);
  for (let i = start; i <= end; i++) {
    btns.push(`<button class="admin-page-btn ${i === page ? 'active' : ''}" onclick="${fnName}(${i})">${i}</button>`);
  }
  if (page < totalPages) btns.push(`<button class="admin-page-btn" onclick="${fnName}(${page + 1})">下一页</button>`);
  return `<div class="admin-pagination">${btns.join('')}</div>`;
}

// ---------- 灵之残响专属房间 ----------

async function renderLzcxRoom(code) {
  if (!store.user) { toast("请先登录", "err"); location.hash = "#/auth"; return; }
  // 自动加入（已在则不重复加）
  await API.post(`/api/lzcxroom/${code}/join`, {});
  const { ok, data } = await API.json(`/api/lzcxroom/${code}`);
  if (!ok) {
    $("#app").innerHTML = `<div class="page">${headerHtml("rooms")}<div class="empty"><div class="empty-icon">🎮</div><p>${escapeHtml(data.error || "房间不存在")}</p><button class="btn btn-secondary" style="margin-top:16px" onclick="location.hash='#/rooms'">返回大厅</button></div></div>`;
    return;
  }
  const room = data.room;
  const soup = data.soup;
  const messages = data.messages || [];
  const members = room.members || [];
  const state = room.state || {};
  store.currentRoomCode = code;
  store.currentRoomHostId = room.host?.id ?? null;
  store.lzcxGameStarted = state.game_started;
  store.lzcxMembersCount = members.length;
  store.lzcxPossessedId = state.possessed_user_id ?? null;
  const isHost = room.host?.id === store.user?.id;

  const keyNodes = state.key_nodes || [];
  const hitCount = keyNodes.filter((n) => n.hit).length;
  const nodeProgress = keyNodes.length ? `${hitCount}/${keyNodes.length}` : "待AI拆分";

  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("rooms")}
      <div class="container room-layout">
        <div class="chat-panel">
          <div class="chat-header">
            <div>
              <div class="chat-title">${escapeHtml(room.code)} <span style="color:var(--accent);font-size:0.85em">[灵之残响]</span></div>
              <div class="chat-code" id="lzcxCodeLine">
                ${room.ai_enabled ? "AI 主持人" : "真人主持（房主）"}
                ${room.ai_question_limit > 0 ? ` · AI提问 ${room.ai_question_count}/${room.ai_question_limit}` : ""}
                · 人数 ${members.length}/4
                ${state.game_started ? "" : " · 等待开始"}
                ${state.cleared ? " · 已通关" : ""}
              </div>
            </div>
            <button class="btn-icon" onclick="location.hash='#/rooms'" title="离开">←</button>
          </div>
          <div class="chat-body" id="chatBody"></div>
          ${room.status === "ended" ? `<div class="chat-ended-notice">房间已结束，无法继续发言</div>` : ""}
          ${(() => {
            const isPossessed = state.possessed_user_id && state.possessed_user_id === store.user?.id;
            const notStarted = !state.game_started;
            const disabled = room.status === "ended" || isPossessed || notStarted;
            let placeholder = "发言…";
            if (notStarted) placeholder = "游戏尚未开始，等待房主开始游戏";
            else if (isPossessed) placeholder = "你正处于幻灵状态，等待他人向你提问";
            return `<div class="chat-input">
              <input id="chatInput" placeholder="${placeholder}" onkeydown="if(event.key==='Enter')sendLzcxChat()" ${disabled ? "disabled" : ""} />
              <button class="btn btn-secondary" onclick="sendLzcxChat()" title="发送" ${disabled ? "disabled" : ""}>💬</button>
              ${room.status !== "ended" && !isPossessed && !notStarted && room.ai_enabled
                ? `<button class="btn btn-primary" onclick="sendLzcxAsk()" title="向AI提问" ${room.ai_question_limit > 0 && room.ai_question_count >= room.ai_question_limit ? "disabled" : ""}>🤖</button>`
                : ""}
            </div>`;
          })()}
        </div>
        <div class="room-side">
          <div class="side-card">
            <h4>当前残响</h4>
            <div class="soup-mini">
              <div class="t">${escapeHtml(soup?.title || "尚未选汤")}</div>
              <div class="s">${escapeHtml(soup?.season || "")}${soup?.episode ? " · " + escapeHtml(soup.episode) : ""}</div>
              <div class="surface">${escapeHtml(soup?.surface || "")}</div>
            </div>
          </div>
          <div class="side-card">
            <h4>剩余理智</h4>
            <div id="lzcxSanityVal" style="font-size:1.6em;font-weight:600;color:var(--accent)">${state.sanity ?? "-"}<span style="font-size:0.6em;color:var(--text-3);margin-left:4px">/ ${state.initial_sanity ?? "-"}</span></div>
            ${!isHost ? `<p class="ai-hint" style="margin:8px 0 0;font-size:0.85em">在聊天框输入 / 可使用角色技能。</p>` : ""}
          </div>
          ${isHost && !state.game_started ? `
          <div class="side-card">
            <h4>开始游戏</h4>
            <p class="ai-hint" style="margin:0 0 10px">当前人数 ${members.length}/4，${members.length === 4 ? '已满员，可以开始游戏' : '需要满 4 人才能开始'}。</p>
            <div class="lzcx-members" style="margin-bottom:10px">
              ${members.map((m) => `
                <div class="lzcx-member" style="align-items:center">
                  <div class="lzcx-member-name">
                    ${escapeHtml(m.username)}
                    ${m.role === "host" ? '<span class="lzcx-role host">房主</span>' : ""}
                  </div>
                  ${m.role !== "host" && room.status !== "ended" ? `
                    <select class="lzcx-char-select" onchange="assignLzcxCharacter(${m.user_id}, this.value)">
                      <option value="">未分配</option>
                      ${(state.characters_meta || []).map((c) => {
                        const info = (state.characters_info || []).find((x) => x.name === c);
                        const label = info ? `${c} · ${info.dept}` : c;
                        return `<option value="${escapeHtml(c)}" ${m.character_name === c ? "selected" : ""}>${escapeHtml(label)}</option>`;
                      }).join("")}
                    </select>
                  ` : ""}
                </div>
              `).join("")}
            </div>
            <button class="btn btn-primary" style="width:100%" onclick="startLzcxGame('${escapeJs(room.code)}')" ${members.length !== 4 ? "disabled" : ""}>开始游戏</button>
          </div>
          ` : ""}
          ${isHost && room.ai_enabled && state.game_started && state.possessed_user_id ? `
          <div class="side-card">
            <h4>幻灵状态</h4>
            <p class="ai-hint" style="margin:0 0 10px">当前有玩家处于幻灵状态。</p>
            <button class="btn btn-secondary" style="width:100%" onclick="sendLzcxHostCommand('/解除幻灵')">解除幻灵</button>
          </div>
          ` : ""}
          ${isHost && !room.ai_enabled ? `
          <div class="side-card host-panel">
            <h4>🎙 主持人面板</h4>
            ${!room.ai_enabled && soup?.base ? `
              <div class="host-base" style="margin-bottom:12px">
                <div class="host-base-label">汤底（仅你可见）</div>
                <div class="host-base-text">${escapeHtml(soup.base || "")}</div>
                ${soup.host_manual ? `<div class="host-base-label" style="margin-top:8px">主持人手册</div><div class="host-base-text">${escapeHtml(soup.host_manual)}</div>` : ""}
              </div>
            ` : ""}
            <p class="ai-hint" style="margin:0 0 8px">纯对话模式：在聊天框输入指令推进游戏。</p>
            <div class="lzcx-host-cmds" style="font-size:0.85em;color:var(--text-3);line-height:1.7;margin-bottom:12px">
              <div><code style="background:var(--bg-2);padding:2px 6px;border-radius:4px">/规则 规则名</code> 触发隐藏规则</div>
              <div><code style="background:var(--bg-2);padding:2px 6px;border-radius:4px">/任务 编号</code> 标记任务完成</div>
              <div><code style="background:var(--bg-2);padding:2px 6px;border-radius:4px">/理智 数值</code> 调整剩余理智</div>
              <div><code style="background:var(--bg-2);padding:2px 6px;border-radius:4px">/解除幻灵</code> 解除当前幻灵状态</div>
              <div><code style="background:var(--bg-2);padding:2px 6px;border-radius:4px">/重置</code> 重置状态机</div>
              <div><code style="background:var(--bg-2);padding:2px 6px;border-radius:4px">/分配 @玩家 角色名</code> 分配角色（仅游戏开始前）</div>
            </div>
            <h4 style="font-size:0.95em;margin:0 0 8px">成员与角色</h4>
            <div class="lzcx-members" id="lzcxMembersBox" style="margin-bottom:8px">
              ${members.map((m) => `
                <div class="lzcx-member" style="align-items:center">
                  <div class="lzcx-member-name">
                    ${escapeHtml(m.username)}
                    ${m.role === "host" ? '<span class="lzcx-role host">房主</span>' : ""}
                    ${m.character_name ? `<span class="lzcx-role char">${escapeHtml(m.character_name)}</span>` : ""}
                  </div>
                </div>
              `).join("")}
            </div>
          </div>
          <div class="side-card">
            <h4>房间管理</h4>
            <button class="btn btn-danger" style="width:100%" onclick="dissolveLzcxRoom('${escapeJs(room.code)}')">解散房间</button>
          </div>
          ` : ""}
        </div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;

  const body = $("#chatBody");
  body.innerHTML = messages.map(renderMsg).join("");
  body.scrollTop = body.scrollHeight;

  if (store.pollTimer) { clearInterval(store.pollTimer); store.pollTimer = null; }
  if (store.lzcxStatePollTimer) { clearInterval(store.lzcxStatePollTimer); store.lzcxStatePollTimer = null; }
  store.pollInFlight = false;
  store.pollLastId = messages.length ? messages[messages.length - 1].id : 0;
  connectLzcxRoom(code);
}

function connectLzcxRoom(code) {
  toast("已加入房间 " + code, "ok");
  if (store.pollTimer) clearInterval(store.pollTimer);
  if (store.lzcxStatePollTimer) clearInterval(store.lzcxStatePollTimer);
  store.pollTimer = setInterval(() => pollLzcxMessages(code), 1500);
  store.lzcxStatePollTimer = setInterval(() => refreshLzcxRoomState(code), 3000);
}

async function pollLzcxMessages(code) {
  if (location.hash !== "#/lzcxroom/" + code) {
    if (store.pollTimer) { clearInterval(store.pollTimer); store.pollTimer = null; }
    if (store.lzcxStatePollTimer) { clearInterval(store.lzcxStatePollTimer); store.lzcxStatePollTimer = null; }
    return;
  }
  if (store.pollInFlight) return;
  store.pollInFlight = true;
  const since = store.pollLastId || 0;
  const { ok, data } = await API.json(`/api/lzcxroom/${code}/messages?since=${since}`);
  store.pollInFlight = false;
  if (!ok || !data.messages) return;
  const body = $("#chatBody");
  if (!body) return;
  if (!data.messages.length) return;
  const nearBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 80;
  data.messages.forEach((m) => {
    const key = msgKey(m);
    if (body.querySelector(`[data-key="${key}"]`)) {
      if (m.id && m.id > (store.pollLastId || 0)) store.pollLastId = m.id;
      return;
    }
    body.insertAdjacentHTML("beforeend", renderMsg(m));
    if (m.id && m.id > (store.pollLastId || 0)) store.pollLastId = m.id;
  });
  if (nearBottom) body.scrollTop = body.scrollHeight;
}

window.sendLzcxChat = async () => {
  const input = $("#chatInput");
  if (!input) return;
  const content = input.value.trim();
  if (!content) return;
  const code = store.currentRoomCode;
  if (!code) { toast("未在房间内", "err"); return; }
  input.value = "";
  const isHost = store.user && store.currentRoomHostId === store.user.id;
  // 房主以 / 开头的发言走主持人指令（纯对话控制状态机）
  if (isHost && content.startsWith("/")) {
    const { ok, data } = await API.post(`/api/lzcxroom/${code}/host-command`, { command: content });
    if (!ok) {
      toast(data.error || "指令失败", "err");
      return;
    }
    if (data.state) refreshLzcxStateUI(code, data.state);
    return;
  }
  // 玩家以 / 开头的发言走技能接口（现！、幻灵等）
  if (!isHost && content.startsWith("/")) {
    const { ok, data } = await API.post(`/api/lzcxroom/${code}/skill`, { content });
    if (!ok) {
      toast(data.error || "技能发动失败", "err");
      return;
    }
    if (data.state) refreshLzcxStateUI(code, data.state);
    return;
  }
  const { ok, data } = await API.post(`/api/lzcxroom/${code}/messages`, { content });
  if (!ok) toast(data.error || "发送失败", "err");
};

window.sendLzcxAsk = async () => {
  const input = $("#chatInput");
  if (!input) return;
  const content = input.value.trim();
  if (!content) return;
  const code = store.currentRoomCode;
  if (!code) { toast("未在房间内", "err"); return; }
  input.value = "";
  const { ok, data } = await API.post(`/api/lzcxroom/${code}/ask`, { content });
  if (!ok) {
    if (data.code === "missing_key") toast("本房间未绑定 AI Key，请房主在房间侧栏绑定", "err");
    else toast(data.error || "提问失败", "err");
    return;
  }
  if (data.error) toast(data.error, "err");
  if (data.state) refreshLzcxStateUI(code, data.state);
};

async function refreshLzcxRoomState(code) {
  const { ok, data } = await API.json(`/api/lzcxroom/${code}`);
  if (!ok || !data.room) return;
  const started = data.room.state?.game_started;
  const membersCount = data.room.members?.length;
  const possessedId = data.room.state?.possessed_user_id ?? null;
  // 游戏状态、人数或幻灵状态变化时重渲染整个房间（开始按钮/输入框禁用态会变化）
  if (started !== store.lzcxGameStarted || membersCount !== store.lzcxMembersCount || possessedId !== store.lzcxPossessedId) {
    renderLzcxRoom(code);
    return;
  }
  refreshLzcxStateUI(code, data.room.state || {}, data.room);
}

function refreshLzcxStateUI(code, state, room) {
  const members = room?.members || [];
  const isHost = room?.host?.id === store.user?.id;

  const codeEl = $("#lzcxCodeLine");
  if (codeEl && room) {
    codeEl.textContent = `${room.ai_enabled ? "AI 主持人" : "真人主持（房主）"}${room.ai_question_limit > 0 ? ` · AI提问 ${room.ai_question_count}/${room.ai_question_limit}` : ""} · 人数 ${members.length}/4${state.game_started ? "" : " · 等待开始"}${state.cleared ? " · 已通关" : ""}`;
  }

  const sanityEl = $("#lzcxSanityVal");
  if (sanityEl) sanityEl.textContent = `${state.sanity ?? "-"}/${state.initial_sanity ?? "-"}`;


  const membersBox = $("#lzcxMembersBox");
  if (membersBox && room) {
    membersBox.innerHTML = members.map((m) => `
      <div class="lzcx-member">
        <div class="lzcx-member-name">
          ${escapeHtml(m.username)}
          ${m.role === "host" ? '<span class="lzcx-role host">房主</span>' : ""}
          ${m.character_name ? `<span class="lzcx-role char">${escapeHtml(m.character_name)}</span>` : ""}
        </div>
      </div>
    `).join("");
  }

}

window.bindLzcxHostKey = async (code) => {
  let key = KeyMgr.get();
  let cfg = KeyMgr.getConfig();
  if (!key) {
    key = prompt("请输入 DeepSeek API Key（sk-...）：\n绑定后房间全员共用此 Key，无需各自配置。");
    if (!key) return;
    key = key.trim();
    cfg = {};
  }
  const { ok, data } = await API.post(`/api/lzcxroom/${code}/ai-key`, {
    api_key: key,
    provider: cfg.provider || "deepseek",
    base_url: cfg.baseUrl || "",
    model: cfg.model || "",
  });
  if (!ok) { toast(data.error || "绑定失败", "err"); return; }
  toast("AI Key 已绑定，房间全员可共用", "ok");
  refreshLzcxRoomState(code);
};

window.unbindLzcxHostKey = async (code) => {
  if (!confirm("确认解绑房间 AI Key？\n解绑后房间内任何人都无法向 AI 提问。")) return;
  const { ok, data } = await API.post(`/api/lzcxroom/${code}/ai-key`, { api_key: "" });
  if (!ok) { toast(data.error || "解绑失败", "err"); return; }
  toast("已解绑", "ok");
  refreshLzcxRoomState(code);
};

window.assignLzcxCharacter = async (userId, character) => {
  const code = store.currentRoomCode;
  if (!code) return;
  const { ok, data } = await API.post(`/api/lzcxroom/${code}/assign-character`, { user_id: userId, character });
  if (!ok) { toast(data.error || "分配失败", "err"); return; }
  toast(character ? `已分配角色：${character}` : "已取消角色", "ok");
  refreshLzcxRoomState(code);
};

window.startLzcxGame = async (code) => {
  const { ok, data } = await API.post(`/api/lzcxroom/${code}/start`, {});
  if (!ok) { toast(data.error || "开始游戏失败", "err"); return; }
  toast("游戏开始", "ok");
  refreshLzcxRoomState(code);
};

window.sendLzcxHostCommand = async (cmd) => {
  const code = store.currentRoomCode;
  if (!code) return;
  const { ok, data } = await API.post(`/api/lzcxroom/${code}/host-command`, { command: cmd });
  if (!ok) { toast(data.error || "指令失败", "err"); return; }
  refreshLzcxRoomState(code);
};

window.triggerLzcxRule = async (code) => {
  const rule = prompt("请输入要触发的规则名（如：规则六）：");
  if (!rule) return;
  const { ok, data } = await API.post(`/api/lzcxroom/${code}/trigger`, { rule: rule.trim() });
  if (!ok) { toast(data.error || "触发失败", "err"); return; }
  toast(data.msg || "已触发", "ok");
  refreshLzcxRoomState(code);
};

window.completeLzcxTask = async (code) => {
  const task = prompt("请输入要标记完成的任务编号（1/2/3...，最终任务填 999）：");
  if (!task) return;
  const { ok, data } = await API.post(`/api/lzcxroom/${code}/complete-task`, { task: parseInt(task) || 0 });
  if (!ok) { toast(data.error || "标记失败", "err"); return; }
  toast(data.msg || "已标记", "ok");
  refreshLzcxRoomState(code);
};

window.setLzcxSanity = async (code) => {
  const sanity = prompt("请输入新的理智值（≥0）：");
  if (sanity === null) return;
  const { ok, data } = await API.put(`/api/lzcxroom/${code}/sanity`, { sanity: parseInt(sanity) || 0 });
  if (!ok) { toast(data.error || "调整失败", "err"); return; }
  toast(data.msg || "已调整", "ok");
  refreshLzcxRoomState(code);
};

window.resetLzcxState = async (code) => {
  if (!confirm("确认重置房间状态机？\n将清空碎片释放、规则触发、任务完成、理智等进度，不可撤销。")) return;
  const { ok, data } = await API.post(`/api/lzcxroom/${code}/reset-state`, {});
  if (!ok) { toast(data.error || "重置失败", "err"); return; }
  toast("状态已重置", "ok");
  refreshLzcxRoomState(code);
};

window.dissolveLzcxRoom = async (code) => {
  if (!confirm("⚠️ 确认解散房间？\n\n房间及所有消息将被永久删除，不可恢复！")) return;
  if (!confirm("再次确认：此操作无法撤销，确定要解散吗？")) return;
  const { ok, data } = await API.del(`/api/lzcxroom/${code}`);
  if (!ok) { toast(data.error || "解散失败", "err"); return; }
  toast("房间已解散", "ok");
  location.hash = "#/rooms";
};

// ---- admin 开灵之残响测试房 ----
window.openLzcxTestRoomModal = async () => {
  if (!store.soups.length) await loadSoups();
  if (!store.soups.length) { toast("汤数据未加载", "err"); return; }
  const lzcxSoups = store.soups.filter((s) => s.season === "灵之残响" && s.status === "approved");
  if (!lzcxSoups.length) { toast("没有可用的灵之残响汤", "err"); return; }
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header"><div><h2 class="modal-title">开灵之残响测试房</h2></div><button class="modal-close" onclick="closeModal(event)">✕</button></div>
      <div class="modal-body">
        <div class="field">
          <label>选择一碗汤 <span class="field-hint">仅灵之残响系列</span></label>
          <select class="input" id="lzcxTestSoup">
            ${lzcxSoups.map((s) => `<option value="${s.id}">${escapeHtml(s.title)}${s.episode ? " · " + escapeHtml(s.episode) : ""}</option>`).join("")}
          </select>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:0.9rem;color:var(--text-2);margin:12px 0 6px">
          <input type="checkbox" id="lzcxTestAi" checked /> 启用 AI 主持人
        </label>
        <div class="field">
          <label>AI 提问次数上限（0 = 无限）</label>
          <input class="input" id="lzcxTestAiLimit" type="number" min="0" max="999" value="0" />
        </div>
        <p class="ai-hint" style="margin-top:12px">灵之残响固定 4 人房（1 房主 + 3 玩家）。</p>
        <button class="btn btn-primary" style="width:100%;margin-top:10px" onclick="createLzcxTestRoom()">创建测试房</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
};

window.createLzcxTestRoom = async () => {
  const soupId = parseInt($("#lzcxTestSoup")?.value) || 0;
  const ai_enabled = $("#lzcxTestAi")?.checked ?? true;
  if (!soupId) { toast("请先选择汤", "err"); return; }
  const { ok, data } = await API.post("/api/lzcxroom", {
    soup_id: soupId,
    ai_enabled,
    ai_question_limit: parseInt($("#lzcxTestAiLimit")?.value) || 0,
  });
  if (!ok) { toast(data.error || "创建失败", "err"); return; }
  if (ai_enabled && KeyMgr.has()) {
    await API.post(`/api/lzcxroom/${data.code}/ai-key`, {
      api_key: KeyMgr.get(),
      ...KeyMgr.getProviderPayload(),
    });
  }
  closeModal();
  location.hash = "#/lzcxroom/" + data.code;
};

// ---------- 初始化 ----------
async function boot() {
  // 拉取用户状态
  const { ok, data } = await API.json("/api/auth/me");
  if (ok && data.user) store.user = data.user;
  if (data && data.csrf_token) store.csrfToken = data.csrf_token;
  // 预加载汤列表（用于房间选汤）
  API.json("/api/soups").then(({ ok, data }) => {
    if (ok) { store.soups = data.soups || []; store.seasons = data.seasons || []; applyFilters(); }
  });
  route();
}

boot();
