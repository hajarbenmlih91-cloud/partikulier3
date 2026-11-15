/**
 * Espace connecté : navigation par panneaux du tableau de bord.
 *
 * Amélioration progressive : sans JavaScript, tous les panneaux restent
 * affichés à la suite (le serveur ne masque rien). Avec JavaScript, un
 * seul panneau est visible, choisi par les tuiles du hub, par le lien
 * profond (#panel-…) ou par le panneau par défaut du serveur.
 */
(function () {
	"use strict";

	var hub = document.querySelector(".pk-hub");
	var wrap = document.querySelector(".pk-espace-panels");
	if (!hub || !wrap) return;

	var buttons = Array.prototype.slice.call(hub.querySelectorAll("[data-panel]"));
	var panels = Array.prototype.slice.call(wrap.querySelectorAll(".pk-espace-panel"));
	if (!buttons.length || !panels.length) return;

	function panelById(id) {
		for (var i = 0; i < panels.length; i++) {
			if (panels[i].id === id) return panels[i];
		}
		return null;
	}

	function activate(id, focusPanel) {
		var target = panelById(id) || panels[0];
		panels.forEach(function (panel) {
			panel.hidden = panel !== target;
		});
		buttons.forEach(function (btn) {
			var active = btn.getAttribute("data-panel") === target.id;
			btn.classList.toggle("is-active", active);
			if (btn.classList.contains("pk-hub-tile")) {
				btn.setAttribute("aria-current", active ? "true" : "false");
			}
		});
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, "", "#" + target.id);
		}
		if (focusPanel) {
			var title = target.querySelector(".pk-espace-panel-title");
			if (title) {
				title.setAttribute("tabindex", "-1");
				title.focus({ preventScroll: true });
			}
			target.scrollIntoView({ block: "start", behavior: "smooth" });
		}
	}

	buttons.forEach(function (btn) {
		btn.addEventListener("click", function () {
			activate(btn.getAttribute("data-panel"), true);
		});
	});

	var fromHash = (window.location.hash || "").replace("#", "");
	var initial = panelById(fromHash)
		? fromHash
		: wrap.getAttribute("data-default-panel") || panels[0].id;
	activate(initial, false);
})();
