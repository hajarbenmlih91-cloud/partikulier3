/**
 * Espace connecté : navigation fidèle à la maquette validée.
 *
 * Le hub est l'état initial ; chaque bouton du hub ouvre le panneau
 * prévu (showPanel), chaque « Retour » ramène au hub (showHub). Fond
 * rouge et logo blanc sur le hub, fond blanc et logo couleur sur les
 * panneaux, comme dans la maquette. La tuile « Mes annonces » ouvre
 * directement le dépôt quand le compte n'a aucune annonce.
 *
 * Hors périmètre de ce fichier : la gestion d'annonce (désactiver /
 * réactiver via pk_manage_listing) et le remplissage de la grille des
 * favoris restent gérés par main.js — ce fichier ne fait que peindre
 * la pagination des grilles et les interactions propres à la feuille
 * (modales du compte, quota, langue, téléphone WhatsApp).
 */
(function () {
	"use strict";

	var root = document.getElementById("pk-espace");
	if (!root) return;

	var hub = document.getElementById("pk-esp-hub");
	var logo = document.getElementById("pk-esp-logo");
	var panels = Array.prototype.slice.call(root.querySelectorAll(".panel"));
	var body = document.body;
	var cfg = window.pkEspace || {};
	var i18n = cfg.i18n || {};

	function panelById(id) {
		for (var i = 0; i < panels.length; i++) {
			if (panels[i].id === id) return panels[i];
		}
		return null;
	}

	function setLogo(color) {
		if (!logo) return;
		var src = color ? logo.getAttribute("data-couleur") : logo.getAttribute("data-blanc");
		if (src && logo.getAttribute("src") !== src) logo.setAttribute("src", src);
	}

	function closeRules() {
		var box = document.getElementById("pk-esp-regles");
		var info = document.getElementById("pk-esp-info");
		if (box) box.hidden = true;
		if (info) info.setAttribute("aria-expanded", "false");
	}

	function closeModal() {
		var voile = document.getElementById("pk-esp-voile");
		if (voile) voile.hidden = true;
	}

	function closeReasons() {
		root.querySelectorAll(".pk-deactivate-reason").forEach(function (box) {
			box.hidden = true;
		});
		root.querySelectorAll('.pk-manage-btn[data-action="deactivate"]').forEach(function (btn) {
			btn.disabled = false;
		});
	}

	function showHub() {
		body.classList.add("is-hub");
		body.classList.remove("is-panel");
		setLogo(false);
		if (hub) hub.hidden = false;
		closeModal();
		closeReasons();
		panels.forEach(function (panel) {
			panel.hidden = true;
		});
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, "", window.location.pathname + window.location.search);
		}
	}

	function showPanel(id) {
		var target = panelById(id);
		if (!target) {
			showHub();
			return;
		}
		body.classList.add("is-panel");
		body.classList.remove("is-hub");
		setLogo(true);
		if (hub) hub.hidden = true;
		closeModal();
		closeReasons();
		closeRules();
		panels.forEach(function (panel) {
			panel.hidden = panel !== target;
		});
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, "", "#" + target.id);
		}
		window.scrollTo(0, 0);
	}

	/* ---------- Toast ---------- */
	var toast = document.getElementById("pk-esp-toast");
	var toastTimer = null;
	var toastDefault = toast ? toast.textContent : "";
	function showToast(message) {
		if (!toast) return;
		toast.textContent = message || toastDefault;
		toast.hidden = false;
		window.clearTimeout(toastTimer);
		toastTimer = window.setTimeout(function () {
			toast.hidden = true;
			toast.textContent = toastDefault;
		}, 1600);
	}

	/* ---------- Pagination des grilles (maquette : 3 mobile, 4 bureau) ---------- */
	var expanded = {};
	function pageLimit() {
		return window.matchMedia("(max-width: 767px)").matches ? 3 : 4;
	}
	function paintList(id) {
		var grid = document.getElementById(id);
		var plus = root.querySelector('.plus[data-grid="' + id + '"]');
		if (!grid) return;
		var items = Array.prototype.slice.call(grid.children);
		var limit = pageLimit();
		var open = !!expanded[id] && items.length > limit;
		items.forEach(function (el, i) {
			el.classList.toggle("suite", !open && i >= limit);
		});
		if (!plus) return;
		var wrap = plus.parentElement;
		if (!items.length || items.length <= limit) {
			plus.hidden = true;
			if (wrap) wrap.hidden = true;
			return;
		}
		plus.hidden = false;
		if (wrap) wrap.hidden = false;
		plus.textContent = open ? (i18n.less || plus.textContent) : (i18n.more || plus.textContent);
	}
	function paintAll() {
		paintList("pk-esp-contacts-grille");
		paintList("pk-esp-annonces-grille");
		paintList("pk-favorites-grid");
	}
	root.querySelectorAll(".plus[data-grid]").forEach(function (plus) {
		plus.addEventListener("click", function () {
			var id = plus.getAttribute("data-grid");
			var grid = document.getElementById(id);
			var total = grid ? grid.children.length : 0;
			expanded[id] = !(expanded[id] && total > pageLimit());
			if (total <= pageLimit()) expanded[id] = false;
			paintList(id);
		});
	});
	/* La grille des favoris est remplie par main.js (AJAX) : repeindre
	 * la pagination quand son contenu change, et masquer le bouton
	 * « plus » quand l'état vide est affiché. */
	var favGrid = document.getElementById("pk-favorites-grid");
	if (favGrid && window.MutationObserver) {
		new MutationObserver(function () {
			paintList("pk-favorites-grid");
		}).observe(favGrid, { childList: true });
	}
	window.addEventListener("resize", paintAll);

	/* ---------- Dépôt / modification (panneau Modifier) ---------- */
	function openDeposit(url) {
		var frame = document.getElementById("pk-esp-depot-frame");
		if (frame && url) frame.setAttribute("src", url);
		showPanel("panel-modifier");
	}
	function fermerDepot() {
		var n = parseInt(root.getAttribute("data-listings") || "0", 10);
		if (n > 0) showPanel("panel-annonces");
		else showHub();
	}

	/* ---------- Navigation du hub ---------- */
	root.querySelectorAll("[data-panel]").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var n = parseInt(root.getAttribute("data-listings") || "0", 10);
			if (btn.id === "pk-esp-hub-annonces" && n === 0) {
				openDeposit(root.getAttribute("data-depot-url"));
				return;
			}
			showPanel(btn.getAttribute("data-panel"));
		});
	});
	root.querySelectorAll("[data-back]").forEach(function (btn) {
		btn.addEventListener("click", showHub);
	});
	var depotRetour = document.getElementById("pk-esp-depot-retour");
	if (depotRetour) depotRetour.addEventListener("click", fermerDepot);
	root.querySelectorAll(".pk-esp-modifier").forEach(function (btn) {
		btn.addEventListener("click", function (event) {
			event.preventDefault();
			openDeposit(btn.getAttribute("data-edit-url"));
		});
	});
	var chip = document.getElementById("pk-esp-chip");
	if (chip) chip.addEventListener("click", showHub);

	/* ---------- Règles du compte (bouton info) ---------- */
	var info = document.getElementById("pk-esp-info");
	var regles = document.getElementById("pk-esp-regles");
	if (info && regles) {
		info.addEventListener("click", function (event) {
			event.stopPropagation();
			regles.hidden = !regles.hidden;
			info.setAttribute("aria-expanded", regles.hidden ? "false" : "true");
		});
		document.addEventListener("click", function (event) {
			if (!regles.hidden && !regles.contains(event.target) && event.target !== info) {
				closeRules();
			}
		});
	}

	/* ---------- Motif de désactivation : fermeture locale ---------- */
	/* L'envoi reste géré par main.js (pk_manage_listing) ; ici, fermer
	 * la boîte rouvre la carte, comme le × du popup de la maquette. */
	root.querySelectorAll(".pk-reason-cancel").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var box = btn.closest(".pk-deactivate-reason");
			if (box) box.hidden = true;
			var item = btn.closest(".pk-listing-item");
			var off = item ? item.querySelector('.pk-manage-btn[data-action="deactivate"]') : null;
			if (off) off.disabled = false;
		});
	});

	/* ---------- Copie du numéro (cartes) + toast ---------- */
	function fallbackCopy(text) {
		var input = document.createElement("textarea");
		input.value = text;
		input.setAttribute("readonly", "");
		input.style.position = "fixed";
		input.style.left = "-9999px";
		document.body.appendChild(input);
		input.select();
		try {
			document.execCommand("copy");
		} catch (e) {
			/* copie indisponible : le toast reste le retour visuel. */
		}
		document.body.removeChild(input);
	}
	root.addEventListener("click", function (event) {
		var btn = event.target.closest ? event.target.closest(".num[data-tel]") : null;
		if (!btn) return;
		event.preventDefault();
		var text = btn.getAttribute("data-tel") || "";
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).catch(function () {
				fallbackCopy(text);
			});
		} else {
			fallbackCopy(text);
		}
		showToast();
	});

	/* ---------- Enregistrement du compte (modales Prénom / E-mail) ---------- */
	function saveProfile(field, value, done) {
		if (!cfg.ajaxUrl || !cfg.nonce) {
			done(new Error(i18n.saveError || "Erreur"));
			return;
		}
		var fd = new FormData();
		fd.append("action", "pk_espace_profil");
		fd.append("nonce", cfg.nonce);
		fd.append("field", field);
		fd.append("value", value);
		fetch(cfg.ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
			.then(function (res) {
				return res.json().then(function (data) {
					if (!res.ok || !data || !data.success) {
						throw new Error((data && data.data && data.data.message) || i18n.saveError || "Erreur");
					}
					return data.data || {};
				});
			})
			.then(function (data) {
				done(null, data);
			})
			.catch(function (err) {
				done(err);
			});
	}

	/* ---------- Modales du compte ---------- */
	var voile = document.getElementById("pk-esp-voile");
	var modalTitle = document.getElementById("pk-esp-modal-titre");
	var modalLabel = document.getElementById("pk-esp-modal-label");
	var modalInput = document.getElementById("pk-esp-modal-valeur");
	var modalOk = document.getElementById("pk-esp-modal-ok");
	var modalError = document.getElementById("pk-esp-modal-error");
	var modalKind = "prenom";

	function openModal(kind) {
		modalKind = kind;
		if (!voile || !modalInput) return;
		if (modalError) modalError.textContent = "";
		if (kind === "email") {
			if (modalTitle) modalTitle.textContent = i18n.modalMailTitle || modalTitle.textContent;
			if (modalLabel) modalLabel.textContent = i18n.modalMailLabel || "Email";
			var valEmail = document.getElementById("pk-esp-val-email");
			modalInput.value = valEmail ? valEmail.textContent.trim() : "";
			modalInput.type = "email";
		} else {
			if (modalTitle) modalTitle.textContent = i18n.modalNameTitle || modalTitle.textContent;
			if (modalLabel) modalLabel.textContent = i18n.modalNameTitle || modalLabel.textContent;
			var valPrenom = document.getElementById("pk-esp-val-prenom");
			modalInput.value = valPrenom ? valPrenom.textContent.trim() : "";
			modalInput.type = "text";
		}
		voile.hidden = false;
		modalInput.focus();
	}
	root.querySelectorAll("[data-modal]").forEach(function (btn) {
		btn.addEventListener("click", function () {
			openModal(btn.getAttribute("data-modal"));
		});
	});
	["pk-esp-modal-x", "pk-esp-modal-fermer"].forEach(function (id) {
		var btn = document.getElementById(id);
		if (btn) btn.addEventListener("click", closeModal);
	});
	if (voile) {
		voile.addEventListener("click", function (event) {
			if (event.target === voile) closeModal();
		});
	}
	document.addEventListener("keydown", function (event) {
		if (event.key === "Escape") {
			closeModal();
			closeRules();
		}
	});
	if (modalOk) {
		modalOk.addEventListener("click", function () {
			if (!modalInput) return;
			var value = modalInput.value.trim();
			if (!value) return;
			modalOk.disabled = true;
			saveProfile(modalKind === "email" ? "email" : "display_name", value, function (err, data) {
				modalOk.disabled = false;
				if (err) {
					if (modalError) modalError.textContent = err.message;
					return;
				}
				if (modalKind === "email") {
					var valEmail = document.getElementById("pk-esp-val-email");
					if (valEmail) valEmail.textContent = (data && data.email) || value;
				} else {
					var name = (data && data.display_name) || value;
					var valPrenom = document.getElementById("pk-esp-val-prenom");
					if (valPrenom) valPrenom.textContent = name;
					var chipName = document.getElementById("pk-esp-chip-name");
					if (chipName) chipName.textContent = name.split(/\s+/)[0];
				}
				closeModal();
				showToast(i18n.saved || "Enregistré");
			});
		});
	}

	/* ---------- Confidentialité : préférence du quota ---------- */
	var quota = document.getElementById("pk-esp-quota-switch");
	if (quota) {
		quota.addEventListener("click", function () {
			var next = quota.getAttribute("aria-pressed") === "true" ? "false" : "true";
			quota.setAttribute("aria-pressed", next);
			saveProfile("quota_reminders", next === "true" ? "1" : "0", function (err) {
				if (err) quota.setAttribute("aria-pressed", next === "true" ? "false" : "true");
			});
		});
	}

	/* ---------- Langue : Enregistrer navigue vers la langue choisie ---------- */
	var langOk = document.getElementById("pk-esp-langue-ok");
	var langSelect = document.getElementById("pk-esp-langue");
	if (langOk && langSelect) {
		langOk.addEventListener("click", function () {
			var opt = langSelect.options[langSelect.selectedIndex];
			var url = opt ? opt.getAttribute("data-url") : "";
			if (url) window.location.href = url;
		});
	}

	/* ---------- Téléphone : confirmation WhatsApp du compte ---------- */
	var telBox = root.querySelector("#panel-telephone .pk-conn-wa");
	if (telBox) {
		var telNumber = telBox.getAttribute("data-wa-number") || "";
		var telTemplate = telBox.getAttribute("data-wa-message") || "";
		var telErrorMsg = telBox.getAttribute("data-wa-error") || "";
		var telInput = document.getElementById("pk-esp-tel-numero");
		var telGo = document.getElementById("pk-esp-tel-go");
		var telFeedback = telBox.querySelector(".pk-conn-feedback");
		var telSuite = document.getElementById("pk-esp-tel-suite");
		var telSuiteLink = document.getElementById("pk-esp-tel-suite-link");

		function telFail() {
			if (telFeedback) telFeedback.textContent = telErrorMsg;
			if (telInput) telInput.focus();
		}
		if (telGo && telInput) {
			telGo.addEventListener("click", function () {
				var raw = telInput.value.replace(/[\s.\-()]+/g, "");
				var national = raw.replace(/^\+?212/, "").replace(/^0+(?=\d)/, "");
				if (!/^[5-7]\d{8}$/.test(national)) {
					telFail();
					return;
				}
				if (telFeedback) telFeedback.textContent = "";
				var full = "+212" + national;
				var message = telTemplate.replace("%s", full);
				var url = "https://wa.me/" + telNumber + "?text=" + encodeURIComponent(message);
				window.open(url, "_blank", "noopener");
				if (telSuite) telSuite.hidden = false;
				if (telSuiteLink) {
					telSuiteLink.setAttribute("href", url);
					telSuiteLink.textContent = "wa.me/" + telNumber;
				}
			});
		}
	}

	/* ---------- État initial : hub, ou panneau du lien profond ---------- */
	paintAll();
	var fromHash = (window.location.hash || "").replace("#", "");
	if (fromHash && panelById(fromHash)) {
		showPanel(fromHash);
	} else {
		showHub();
	}
})();
