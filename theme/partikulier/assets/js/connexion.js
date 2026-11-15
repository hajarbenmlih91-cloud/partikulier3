/**
 * Page de connexion : parcours fidèle à la maquette de l'espace.
 *
 * - Bloc e-mail : visible tel quel sans JavaScript et quand le serveur
 *   l'a déplié (?auth_item) ; sinon, le bouton « E-mail » le déplie.
 * - Bouton WhatsApp : normalise le mobile marocain saisi, puis ouvre
 *   wa.me vers le numéro d'équipe du site avec le message pré-rempli.
 *   Aucune donnée n'est envoyée ailleurs que dans WhatsApp.
 */
(function () {
	"use strict";

	/* ---------- Bloc e-mail dépliable ---------- */
	var toggle = document.getElementById("pk-conn-email-toggle");
	var emailBox = document.getElementById("pk-conn-email");
	if (toggle && emailBox) {
		var serverOpen = emailBox.hasAttribute("data-open");
		if (!serverOpen) {
			emailBox.hidden = true;
			toggle.setAttribute("aria-expanded", "false");
		}
		toggle.addEventListener("click", function () {
			var willOpen = emailBox.hidden;
			emailBox.hidden = !willOpen;
			toggle.setAttribute("aria-expanded", willOpen ? "true" : "false");
			if (willOpen) {
				var first = emailBox.querySelector("input, select, textarea");
				if (first) first.focus({ preventScroll: false });
			}
		});
	}

	/* ---------- Ouverture de compte par WhatsApp ---------- */
	var waBox = document.querySelector(".pk-conn-wa");
	var goBtn = document.getElementById("pk-conn-wa-go");
	var input = document.getElementById("pk-conn-numero");
	if (waBox && goBtn && input) {
		var team = (waBox.getAttribute("data-wa-number") || "").replace(/\D+/g, "");
		var template = waBox.getAttribute("data-wa-message") || "";
		var errorMsg = waBox.getAttribute("data-wa-error") || "";
		var feedback = waBox.querySelector(".pk-conn-feedback");

		function fail() {
			if (feedback) feedback.textContent = errorMsg;
			input.setAttribute("aria-invalid", "true");
			input.focus();
		}

		goBtn.addEventListener("click", function () {
			var digits = input.value.replace(/\D+/g, "");
			if (digits.indexOf("00212") === 0) digits = digits.slice(2);
			if (digits.charAt(0) === "0") digits = "212" + digits.slice(1);
			if (/^[67]\d{8}$/.test(digits)) digits = "212" + digits;
			if (!/^212[67]\d{8}$/.test(digits) || !team) {
				fail();
				return;
			}
			input.removeAttribute("aria-invalid");
			if (feedback) feedback.textContent = "";
			var text = template.replace("%s", "+" + digits);
			window.open("https://wa.me/" + team + "?text=" + encodeURIComponent(text), "_blank", "noopener");
		});

		input.addEventListener("keydown", function (ev) {
			if (ev.key === "Enter") {
				ev.preventDefault();
				goBtn.click();
			}
		});
	}
})();
