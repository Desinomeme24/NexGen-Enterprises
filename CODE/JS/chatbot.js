(function () {
  // Repeated script includes must not attach another toggle/send handler.
  if (window.NexGenChatbot && typeof window.NexGenChatbot.init === "function") {
    window.NexGenChatbot.init();
    return;
  }

  const initializedWidgets = new WeakSet();

  function initWidget(widget) {
    if (initializedWidgets.has(widget)) {return;}
    const toggleBtn = widget.querySelector("#nxChatbotToggle");
    const chatBox = widget.querySelector("#nxChatbotBox");
    const closeBtn = widget.querySelector("#nxChatbotClose");
    const form = widget.querySelector("#nxChatbotForm");
    const input = widget.querySelector("#nxChatbotInput");
    const messages = widget.querySelector("#nxChatbotMessages");
    const chips = widget.querySelectorAll(".nx-chip");
    const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
    const ENDPOINT = widget.dataset.endpoint || "chatbot.php?action=ask";
    const REQUEST_TIMEOUT_MS = 35000;
    let requestInFlight = false;
    let activeController = null;

    if (
      !toggleBtn ||
      !chatBox ||
      !closeBtn ||
      !form ||
      !input ||
      !messages ||
      !submitBtn
    )
      {return;}

    // Do not mark a partial widget ready; initialization can retry after parsing.
    initializedWidgets.add(widget);

    function setBusy(isBusy) {
      requestInFlight = isBusy;
      submitBtn.disabled = isBusy;
      submitBtn.setAttribute("aria-busy", isBusy ? "true" : "false");
      chips.forEach((chip) => {
        chip.disabled = isBusy;
      });
    }

    function escapeHtml(text) {
      const div = document.createElement("div");
      div.textContent = text;
      return div.innerHTML;
    }

    function appendMessage(type, html) {
      const msg = document.createElement("div");
      msg.className = "nx-msg " + type;
      msg.innerHTML = html;
      messages.appendChild(msg);
      messages.scrollTop = messages.scrollHeight;
    }

    function appendTyping() {
      const typing = document.createElement("div");
      typing.className = "nx-typing";
      typing.id = "nxTyping";
      typing.innerHTML = "<span></span><span></span><span></span>";
      messages.appendChild(typing);
      messages.scrollTop = messages.scrollHeight;
    }

    function removeTyping() {
      const typing = messages.querySelector(".nx-typing");
      if (typing) {typing.remove();}
    }

    function renderBotReply(reply) {
      if (!reply) {return escapeHtml("No response received.");}
      const trimmed = String(reply).trim();

      if (trimmed.includes("[OPEN_CONFIRM]|")) {
        const lines = trimmed.split("\n");
        const markerLine = lines.find((line) =>
          line.includes("[OPEN_CONFIRM]|"),
        );
        const normalLines = lines.filter(
          (line) => !line.includes("[OPEN_CONFIRM]|"),
        );
        const parts = markerLine.split("|");
        const label = parts[1] || "Module";
        const url = parts[2] || "#";

        let html = "";
        if (normalLines.length > 0) {
          html +=
            escapeHtml(normalLines.join("\n")).replace(/\n/g, "<br>") + "<br>";
        }

        html += `
                  <div class="nx-open-card">
                      <strong>${escapeHtml(label)}</strong>
                      <div class="nx-open-actions">
                          <button type="button" class="nx-open-btn" data-open-url="${escapeHtml(url)}">Open Now</button>
                          <button type="button" class="nx-cancel-btn">Cancel</button>
                      </div>
                  </div>
              `;
        return html;
      }

      return escapeHtml(trimmed).replace(/\n/g, "<br>");
    }

    async function sendMessage(text) {
      const message = (text || input.value).trim();
      if (!message || requestInFlight) {return;}

      appendMessage("user", escapeHtml(message).replace(/\n/g, "<br>"));
      input.value = "";
      appendTyping();
      setBusy(true);

      activeController = new AbortController();
      const timeoutId = setTimeout(() => {
        if (activeController) {activeController.abort();}
      }, REQUEST_TIMEOUT_MS);

      try {
        const formData = new FormData();
        formData.append("message", message);
        formData.append("context", widget.dataset.context || "");

        const response = await fetch(ENDPOINT, {
          method: "POST",
          body: formData,
          credentials: "same-origin",
          signal: activeController.signal,
        });

        const rawText = await response.text();

        let data = null;
        try {
          data = JSON.parse(rawText);
        } catch (parseError) {
          console.error(
            "Chatbot returned a non-JSON response",
            response.status,
          );
          appendMessage(
            "bot",
            "Sorry, I had trouble reading the server reply. Please refresh the page and try again.",
          );
          return;
        }

        if (!response.ok || !data.success) {
          appendMessage(
            "bot",
            renderBotReply(
              data.reply ||
                "Sorry, something went wrong while getting a reply.",
            ),
          );
          return;
        }

        if (data && data.reply) {
          appendMessage("bot", renderBotReply(data.reply));
        } else {
          appendMessage(
            "bot",
            "Sorry, something went wrong while getting a reply.",
          );
        }
      } catch (error) {
        if (error && error.name === "AbortError") {
          appendMessage(
            "bot",
            "That request took too long, so I stopped waiting for it. Please try a shorter or more specific question.",
          );
        } else {
          console.error("Chatbot fetch error:", error);
          appendMessage(
            "bot",
            "Sorry, I couldn’t connect right now. Please refresh the page and try again.",
          );
        }
      } finally {
        clearTimeout(timeoutId);
        activeController = null;
        removeTyping();
        setBusy(false);
        input.focus();
      }
    }

    toggleBtn.addEventListener("click", function () {
      chatBox.classList.toggle("show");
      if (chatBox.classList.contains("show"))
        {setTimeout(() => input.focus(), 120);}
    });

    closeBtn.addEventListener("click", function () {
      chatBox.classList.remove("show");
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      sendMessage();
    });

    chips.forEach((chip) => {
      chip.addEventListener("click", function () {
        const text = this.textContent.trim();
        if (!chatBox.classList.contains("show")) {chatBox.classList.add("show");}
        if (text.includes("<name>")) {
          input.value = text.replace("<name>", "");
          input.focus();
          return;
        }
        sendMessage(text);
      });
    });

    messages.addEventListener("click", function (e) {
      const openBtn = e.target.closest(".nx-open-btn");
      const cancelBtn = e.target.closest(".nx-cancel-btn");

      if (openBtn) {
        const url = openBtn.getAttribute("data-open-url");
        if (url) {
          const target = new URL(url, window.location.href);
          const endpoint = new URL(ENDPOINT, window.location.href);
          const allowed = [
            "dashboard.php",
            "inventory_management.php",
            "sales_recording.php",
            "sales_analytics.php",
            "accounts_receivable.php",
            "settings.php",
            "about_us.php",
          ];
          const base = endpoint.pathname.slice(
            0,
            endpoint.pathname.lastIndexOf("/") + 1,
          );
          if (
            target.origin === window.location.origin &&
            allowed.some((file) => target.pathname === base + file)
          ) {
            window.location.href = target.href;
          }
        }
      }

      if (cancelBtn) {
        const card = cancelBtn.closest(".nx-open-card");
        if (card) {card.remove();}
      }
    });
  }

  function init() {
    document.querySelectorAll(".nx-chatbot-widget").forEach(initWidget);
  }

  window.NexGenChatbot = { init };
  init();
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  }

  // Also support a widget inserted later by a page/partial loader. Capturing
  // initializes its controls before this first click reaches the button.
  document.addEventListener(
    "click",
    function (event) {
      if (!(event.target instanceof Element)) {return;}
      const widget = event.target.closest(".nx-chatbot-widget");
      if (widget) {initWidget(widget);}
    },
    true,
  );
})();
