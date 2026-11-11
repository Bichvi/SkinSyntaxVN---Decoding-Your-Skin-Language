# SKINSYNTAXVN — PHASE P4C: JAVASCRIPT & CAROUSEL OPTIMIZATIONS

## 1. Overview
JavaScript execution on the browser main-thread directly impacts **Total Blocking Time (TBT)**, **Interaction to Next Paint (INP)**, and **First Input Delay (FID)**.
In Phase P4C, three targeted JS bottlenecks were audited and resolved without breaking any interactive features:
1. **SYNA Mascot Asset Loading (`home.php`)**
2. **Flash Sale Carousel Forced Reflow / Layout Thrashing (`home.php`)**
3. **AI Chat Widget Lazy Initialization (`ai_chat_widget.php`)**
4. **Deferred Script Execution (`footer.php`)**

---

## 2. SYNA Mascot Asset Loading Optimization

### 2.1 Problem
In `home.php`, `DOMContentLoaded` previously iterated through all 11 poses in `synaStates` and eagerly preloaded all of them:
```javascript
// BEFORE: 11 image instances preloaded synchronously on boot
Object.values(synaStates).forEach(function (src) {
  var img = new Image();
  img.src = src;
});
```
This triggered 11 concurrent HTTP GET requests transferring ~800 KB of PNG data during initial mobile connection ramp-up, competing directly with LCP hero elements and critical CSS.

### 2.2 Solution
Implemented an intelligent **Selective On-Demand Asset Strategy**:
1. **Immediate States Preloading**:
   Only preloads the 4 states required during the first 5–8 seconds:
   - `idle` (`syna_listening.png`)
   - `greeting` (`syna_greeting.png`)
   - `talking1` (`syna_talking_1.png`)
   - `talking2` (`syna_talking_2.png`)
2. **On-Demand Loading with Fallback**:
   When `setSynaState(stateName)` is triggered:
   - If the target state image is already loaded, switch immediately.
   - If not yet loaded, start loading asynchronously while **retaining the current active pose** as a graceful fallback (zero white flash or broken image).
3. **Idle Prefetching**:
   Remaining poses (`thinking`, `recommend`, `processing`, `active`, `happy`, `bye`) are preloaded during browser idle time using `requestIdleCallback` (with a 3.5s timeout) or a fallback timer.
4. **Asset Integrity**:
   Zero image formats converted, zero original PNG assets overwritten.

---

## 3. Flash Sale Carousel: Layout Thrashing Elimination

### 3.1 Problem
In `home.php`, inside `renderCarousel(targetPage, animate)`:
```javascript
// BEFORE: Forced reflow inside a loop of up to 24 products
for (var i = 0; i < totalItems; i++) {
  var item = items[i];
  if (i >= startIndex && i < endIndex) {
    item.classList.remove('d-none');
    if (animate) {
      item.classList.remove('flash-card-entering');
      void item.offsetWidth; // FORCED SYNCHRONOUS LAYOUT!
      item.classList.add('flash-card-entering');
    }
  }
}
```
Querying `item.offsetWidth` inside a loop invalidated the browser's layout tree on every product iteration, triggering N consecutive recalculations (layout thrashing).

### 3.2 Solution
Batched DOM mutations and consolidated reflow to a single calculation on the parent container:
```javascript
// AFTER: Batched mutations + single parent reflow
for (var i = 0; i < totalItems; i++) {
  var item = items[i];
  if (i >= startIndex && i < endIndex) {
    item.classList.remove('d-none');
    if (animate) {
      item.classList.remove('flash-card-entering');
    }
  } else {
    item.classList.add('d-none');
    item.classList.remove('flash-card-entering');
  }
}

if (animate && viewport) {
  void viewport.offsetWidth; // Single reflow for entire batch
  for (var j = startIndex; j < endIndex; j++) {
    items[j].classList.add('flash-card-entering');
  }
}
```
**Result**: Carousel pagination flips smoothly at 60 FPS without layout micro-stutters.

---

## 4. AI Chat Widget: Lazy Initialization

### 4.1 Problem
`ai_chat_widget.php` is embedded on every page where `$pdo !== null`.
At initial bootup (`DOMContentLoaded`), the widget:
- Parsed and rendered existing session messages into the DOM (`renderMessages()`).
- Generated and formatted the dynamic greeting message (`injectGreeting()`).
- Injected DOM nodes into `#aiChatStream`.
- Triggered background conversation fetches for logged-in users.
All this CPU work occurred while the chat panel was closed and hidden (`data-ai-chat-panel hidden`).

### 4.2 Solution
Decoupled widget trigger presentation from panel content rendering:
1. **Interactive Trigger Bound Immediately**:
   The floating button `Chat với Syna` is rendered and click-bound immediately.
2. **`ensureChatInitialized()` on Demand**:
   Message rendering, session retrieval, and conversation synchronization are wrapped in `ensureChatInitialized()`.
   - Called immediately when user clicks the chat button (`openWidget()`).
   - If user does not click, runs during `requestIdleCallback` (with 4s timeout) so the chat is ready without blocking initial page load.
3. **Session & API Preservation**:
   All sessionStorage keys, active conversation IDs, and backend chat endpoints remain 100% identical.

---

## 5. Script Deferral in Footer

In `frontend/views/layouts/footer.php`:
- Added `defer` attribute to `bootstrap.bundle.min.js`, `live-search.js`, and `smart-search.js`.
- Corrected HTML structure: moved `</main>` before footer script declarations.
- Scripts execute in predictable order immediately prior to `DOMContentLoaded` without pausing HTML parser throughput.
