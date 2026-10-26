function pkLimitTitle(value) {
	var text = String(value || "").replace(/\s+/g, " ").trim();
	if (text.length <= 120) {
		return text;
	}
	var cut = text.slice(0, 120);
	var space = cut.lastIndexOf(" ");
	if (space >= 10) {
		cut = cut.slice(0, space);
	}
	return cut.replace(/[\s,.;:\u2014-]+$/g, "");
}

/**
 * Partikulier — scripts front-end.
 * Zero jQuery. ES6 natif, ~3 ko minifie.
 *
 * - Menu mobile (toggle + ESC)
 * - Dropdowns clavier
 * - Dropzone photos du formulaire d'annonce
 * - Soumission AJAX du formulaire d'annonce (fetch + FormData)
 */
(function () {
		"use strict";

		/* ---------- Accessibilite Estatik ----------
			Estatik peut rendre le champ d'informations complementaires avec un id
			dynamique mais sans label associe. On ajoute un nom accessible seulement
			si le plugin n'en a pas deja fourni, y compris apres injection AJAX. */
	function labelEstatikExtraInfo() {
			var lang   = (document.documentElement.lang || "fr").toLowerCase().slice(0, 2);
			var labels = {
				fr: "Informations complémentaires",
				en: "Additional information",
				ar: "معلومات إضافية"
		};
			var label  = labels[lang] || labels.fr;
			document.querySelectorAll('input[id^="es_extra_info-"], textarea[id^="es_extra_info-"], select[id^="es_extra_info-"]').forEach(function (field) {
					var associatedLabel = field.id ? document.querySelector('label[for="' + CSS.escape(field.id) + '"]') : null;
					var hasLabel        = field.getAttribute("aria-label") || field.getAttribute("aria-labelledby") || (associatedLabel && associatedLabel.textContent.trim());
					if ( ! hasLabel) field.setAttribute("aria-label", label);
			});
	}
		labelEstatikExtraInfo();
	if (window.MutationObserver && document.body) {
			new MutationObserver(labelEstatikExtraInfo).observe(document.body, { childList: true, subtree: true });
	}

		/* ---------- Menu mobile ---------- */
		var toggle     = document.querySelector(".pk-nav-toggle");
		var mobileMenu = document.getElementById("pk-mobile-menu");
	if (toggle && mobileMenu) {
					toggle.addEventListener("click", function () {
							var open = toggle.classList.toggle("pk-open");
						if (open) {
								mobileMenu.removeAttribute("hidden");
						} else {
								mobileMenu.setAttribute("hidden", "");
						}
						toggle.setAttribute("aria-expanded", open ? "true" : "false");
					});
			document.addEventListener("keydown", function (e) {
				if (e.key === "Escape" && toggle.classList.contains("pk-open")) {
						toggle.click();
						toggle.focus();
				}
			});
	}

		/* ---------- Fermeture dropdown au clic exterieur ---------- */
		document.addEventListener("click", function (e) {
			if ( ! e.target.closest(".pk-has-children")) {
					var open = document.querySelectorAll(".pk-has-children.pk-focus");
				for (var i = 0; i < open.length; i++) {
						open[i].classList.remove("pk-focus");
				}
			}
		});

		/* ---------- Dropzone photos ----------
			Le parcours de depot en 3 etapes (submit-steps.js) gere lui-meme ses
			photos. Deux gestionnaires sur le meme champ se neutralisent : celui-ci
			vidait l'input apres chaque selection. On ne l'active donc que sur
			l'ancien formulaire, sans etapes. */
		var dropzone  = document.getElementById("pk-dropzone");
		var fileInput = document.getElementById("pk-photos");
		var preview   = document.getElementById("pk-photo-preview");
	if (dropzone && fileInput && preview && ! document.querySelector(".pk-steps")) {
			var MAX_FILES = 15;
			var selected  = [];

			dropzone.addEventListener("click", function () {
					fileInput.click();
			});

			["dragenter", "dragover"].forEach(function (type) {
					dropzone.addEventListener(type, function (e) {
							e.preventDefault();
							dropzone.classList.add("dragover");
					});
			});
			["dragleave", "drop"].forEach(function (type) {
					dropzone.addEventListener(type, function (e) {
							e.preventDefault();
							dropzone.classList.remove("dragover");
					});
			});
			dropzone.addEventListener("drop", function (e) {
					addFiles(Array.prototype.slice.call(e.dataTransfer.files));
			});
			fileInput.addEventListener("change", function () {
					addFiles(Array.prototype.slice.call(fileInput.files));
					fileInput.value = "";
			});

		function addFiles(files) {
			files     = files.filter(function (f) {
					return /^image\/(jpeg|png|webp|avif)$/.test(f.type);
			});
			var total = selected.length + files.length;
			files     = files.slice(0, Math.max(0, MAX_FILES - selected.length));
			files.forEach(function (f) {
					selected.push(f);
					var li      = document.createElement("li");
					var img     = document.createElement("img");
					img.src     = URL.createObjectURL(f);
					img.alt     = f.name;
					img.loading = "lazy";
					li.appendChild(img);
					li.title        = "Retirer " + f.name;
					li.style.cursor = "pointer";
					var idx         = selected.length - 1;
					li.addEventListener("click", function () {
							selected[idx] = null;
							li.remove();
							syncDataTransfer();
					});
					preview.appendChild(li);
			});
			if (total > MAX_FILES) {
				setStatus(MAX_FILES + " photos maximum. Les fichiers excédentaires ont été ignorés.");
			}
			syncDataTransfer();
		}

			/**
			 * Recree le DataTransfer du champ file avec les fichiers conserves
			 * (le <input multiple> natif ne permet pas de retirer un fichier).
			 */
		function syncDataTransfer() {
				var dt = new DataTransfer();
				selected.forEach(function (f) {
						if (f) dt.items.add(f);
				});
				fileInput.files = dt.files;
		}
	}

		/* ---------- Favoris (wishlist) en localStorage ---------- */
		var wishlistButtons = document.querySelectorAll(".pk-card-wishlist");
				// Expose le branchement pour les cartes chargees en AJAX (page Favoris).
				window.pkBindWishlist = function () {
						var saved = [];
					try { saved = JSON.parse(window.localStorage.getItem("pk_wishlist") || "[]"); } catch (x) { saved = []; }

					document.querySelectorAll(".pk-card-wishlist").forEach(function (b) {
								// Etat au chargement : un favori deja enregistre s'affiche rouge.
								var currentId = b.getAttribute("data-post-id");
						if (saved.indexOf(currentId) !== -1) {
								b.classList.add("pk-wish-active");
								b.setAttribute("aria-pressed", "true");
								b.setAttribute("aria-label", "Retirer des favoris");
						} else {
								b.setAttribute("aria-pressed", "false");
						}

								if (b.dataset.pkBound) return;
								b.dataset.pkBound = "1";
								b.addEventListener("click", function (e) {
										e.preventDefault();
										e.stopPropagation();
										var id   = b.getAttribute("data-post-id");
										var list = [];
									try { list = JSON.parse(window.localStorage.getItem("pk_wishlist") || "[]"); } catch (x) { list = []; }
										var i = list.indexOf(id);
									if (i === -1) {
											list.push(id);
											b.classList.add("pk-wish-active");
											b.setAttribute("aria-pressed", "true");
											b.setAttribute("aria-label", "Retirer des favoris");
									} else {
											list.splice(i, 1);
											b.classList.remove("pk-wish-active");
											b.setAttribute("aria-pressed", "false");
											b.setAttribute("aria-label", "Ajouter aux favoris");
									}
									try { window.localStorage.setItem("pk_wishlist", JSON.stringify(list)); } catch (x) {}

										// Compteur serveur anonyme, defini plus bas ; absent sur
										// certaines pages, d'ou la verification.
									if (typeof window.pkSyncFavorite === "function") {
											window.pkSyncFavorite(id, i === -1);
									}

										// Confirmation visuelle du geste.
										b.classList.remove("pk-wish-pop");
										void b.offsetWidth;
										b.classList.add("pk-wish-pop");
								});
					});
				};
				window.pkBindWishlist();
	if (wishlistButtons.length) {
			var WISH_KEY       = "pk_wishlist";
			var VISITOR_KEY    = "pk_favorite_visitor";
			var getWishlist    = function () {
				try {
								return JSON.parse(window.localStorage.getItem(WISH_KEY) || "[]");
				} catch (e) {
								return [];
				}
			};
			var setWishlist    = function (list) {
				try {
					window.localStorage.setItem(WISH_KEY, JSON.stringify(list));
				} catch (e) {
					// Espace localStorage indisponible : rien de grave.
				}
			};
			var getVisitorId   = function () {
				try {
					var visitor = window.localStorage.getItem(VISITOR_KEY);
					if ( ! visitor) {
												visitor = window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID().replace(/-/g, "") : ("pk" + Date.now() + Math.random().toString(36).slice(2));
												window.localStorage.setItem(VISITOR_KEY, visitor);
					}
											return visitor;
				} catch (e) {
					return "";
				}
			};
			var syncFavorite   = function (id, active) {
										if ( ! id || ! pkConfig || ! pkConfig.ajaxUrl || ! pkConfig.nonce) return;
										var visitor = getVisitorId();
										if ( ! visitor || ! window.fetch) return;
										var body = new URLSearchParams({
											action: "pk_sync_favorite",
											nonce: pkConfig.nonce,
											post_id: id,
											visitor_id: visitor,
											state: active ? "save" : "remove"
										});
										window.fetch(pkConfig.ajaxUrl, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" }, body: body.toString() }).catch(function () {
												// Le favori local demeure disponible si le compteur serveur est indisponible.
										});
			};
			var syncWishlistUI = function () {
								var list = getWishlist();
						document.querySelectorAll(".pk-card-wishlist").forEach(function (btn) {
								var active = list.indexOf(btn.getAttribute("data-post-id")) !== -1;
								btn.classList.toggle("pk-wish-active", active);
								btn.setAttribute("aria-pressed", active ? "true" : "false");
						});
			};
					// Un seul gestionnaire de clic existe : window.pkBindWishlist plus haut.
					// Ce bloc n'expose que le compteur serveur et l'etat initial ; ajouter
					// ici un second addEventListener annulerait le premier basculement.
					window.pkSyncFavorite = syncFavorite;
					syncWishlistUI();
	}

		/* ---------- Titre automatique d'annonce ---------- */
		var titleInput                  = document.getElementById("pk-title");
		var typeSelect                  = document.getElementById("pk-type");
		var actionSelect                = document.getElementById("pk-action");
		var citySelect                  = document.getElementById("pk-city");
				var surfaceInput        = document.getElementById("pk-surface");
				var bedroomsSelect      = document.getElementById("pk-bedrooms");
				var livingRoomsSelect   = document.getElementById("pk-living-rooms");
				var terraceSelect       = document.getElementById("pk-terrace");
				var terraceSurfaceInput = document.getElementById("pk-terrace-surface");
				var visAVisSelect       = document.getElementById("pk-vis-a-vis");
				var sunshineSelect      = document.getElementById("pk-sunshine");
				var titleWasEdited      = false;

	function selectedLabel(select) {
			// Le parcours en 3 etapes remplace certains <select> par des champs
			// texte a autocompletion : on ne suppose plus la presence d'options.
			if ( ! select) return "";
		if ( ! select.options || typeof select.selectedIndex !== "number") {
				return select.value ? String(select.value).trim() : "";
		}
			var option = select.options[select.selectedIndex];
			return option ? option.text.trim() : "";
	}

	function titleRoomLayout(type, bedrooms, livingRooms) {
			if ("studio" === type.toLowerCase() || "0" === bedrooms) return "";
			if ("1" === bedrooms && "1" === livingRooms) return " — 1 chambre + salon";
			if ("2" === bedrooms && "1" === livingRooms) return " — 2 chambres + salon";
			if ("3+" === bedrooms) return "";
			return "";
	}

	function generateListingTitle() {
			if ( ! titleInput) return;
			// Le parcours en 3 etapes redige titre et description cote serveur
			// (submit-steps.js) : on n'ecrase pas son resultat.
			if (document.querySelector(".pk-steps")) return;
			var type                     = selectedLabel(typeSelect) || "Appartement";
			var bedrooms                 = bedroomsSelect ? bedroomsSelect.value : "";
			var livingRooms              = livingRoomsSelect ? livingRoomsSelect.value : "";
			var isStudio                 = "studio" === type.toLowerCase() || "0" === bedrooms;
			var typeLabel                = isStudio ? "Studio" : type;
			var size                     = surfaceInput && surfaceInput.value ? " de " + surfaceInput.value + " m²" : "";
					var place            = selectedLabel(citySelect) || "votre ville";
					var action           = selectedLabel(actionSelect).toLowerCase();
					var transaction      = action.indexOf("lou") !== -1 ? "à louer" : "à vendre";
					var visAVis          = visAVisSelect ? visAVisSelect.value : "Non";
					var sunshine         = sunshineSelect ? sunshineSelect.value : "";
					var primaryAdvantage = visAVis === "Oui" ? " sans vis-à-vis"
							: sunshine === "Toute la journée" ? " bien ensoleillé"
							: sunshine === "Ensoleillé le matin" ? " ensoleillé le matin"
							: sunshine === "Ensoleillé l’après-midi" ? " ensoleillé l’après-midi"
							: "";
					var terrace          = terraceSelect && terraceSelect.value === "Oui"
							? " avec terrasse" + (terraceSurfaceInput && terraceSurfaceInput.value ? " de " + terraceSurfaceInput.value + " m²" : "")
							: "";
					titleInput.value     = pkLimitTitle(typeLabel + titleRoomLayout(type, bedrooms, livingRooms) + size + primaryAdvantage + terrace + " " + transaction + " à " + place);
	}

	if (titleInput) {
			titleInput.addEventListener("input", function () {
					titleWasEdited = true;
			});
					[typeSelect, actionSelect, citySelect, surfaceInput, bedroomsSelect, livingRoomsSelect, terraceSelect, terraceSurfaceInput, visAVisSelect, sunshineSelect].forEach(function (field) {
						if (field) field.addEventListener("change", function () {
								if ( ! titleWasEdited) generateListingTitle();
						});
					});
	}

		/* ---------- Soumission du formulaire d'annonce ---------- */
				var terraceSurfaceField = document.getElementById("pk-terrace-surface-field");
	function syncTerraceField() {
			if ( ! terraceSelect || ! terraceSurfaceField || ! terraceSurfaceInput) return;
			var hasTerrace                               = terraceSelect.value === "Oui";
			terraceSurfaceField.hidden                   = ! hasTerrace;
			terraceSurfaceInput.required                 = hasTerrace;
			if ( ! hasTerrace) terraceSurfaceInput.value = "";
	}
	if (terraceSelect) {
			terraceSelect.addEventListener("change", syncTerraceField);
			syncTerraceField();
	}
		var form      = document.getElementById("pk-submit-form");
		var submitBtn = document.getElementById("pk-submit-btn");
		var statusEl  = document.getElementById("pk-form-status");
	if (form && submitBtn) {
			form.addEventListener("submit", function (e) {
					e.preventDefault(); // Toujours intercepter pour éviter le rechargement brut ou le blocage silencieux

					if (titleInput && ! titleInput.value.trim() && typeof generateListingTitle === "function") {
							generateListingTitle();
					}

				// Champs visibles : required + minlength/pattern (novalidate sur le form).
				var missing = [];
				form.querySelectorAll("input, select, textarea").forEach(function (inp) {
						if (inp.disabled || inp.type === "hidden") return;
						var step = inp.closest(".pk-step");
						if (step && step.hidden) return;
						if (inp.closest("[hidden]")) return;
						inp.classList.remove("pk-invalid");
						if ((inp.required && ! String(inp.value).trim()) || ! inp.checkValidity()) {
								missing.push(inp);
								inp.classList.add("pk-invalid");
						}
				});

				if (missing.length > 0) {
						missing[0].focus();
						setStatus((window.pkConfig && pkConfig.i18n && pkConfig.i18n.requiredFields) || "Veuillez vérifier les champs obligatoires.");
						if (statusEl) statusEl.style.color = "#c0392b";
						return;
				}

					submitBtn.disabled    = true;
					var originalText      = submitBtn.textContent;
					submitBtn.textContent = (pkConfig.i18n && pkConfig.i18n.publishing) || "Publication en cours…";
					setStatus("⏳ Enregistrement de votre annonce…");
					if (statusEl) statusEl.style.color = "var(--pk-primary)";

					var fd = new FormData(form);
					/* admin-ajax n'a pas de contexte Polylang : sans cette langue explicite,
					   le serveur résout __() et la langue de l'annonce en FR même depuis /ar/. */
					fd.append("pk_language", (typeof pkConfig !== "undefined" && pkConfig.language) ? pkConfig.language : (document.documentElement.lang || "fr"));

					fetch(pkConfig.ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
							.then(function (res) {
									return res.json().then(function (data) {
											if ( ! res.ok || ! data.success) {
													var errMsg = (data && data.data && data.data.message) || ((pkConfig.i18n && pkConfig.i18n.serverError) || "Erreur serveur");
													throw new Error(errMsg);
											}
											return data;
									});
							})
							.then(function (data) {
									var payload = data && data.data ? data.data : {};
								if ("pending_whatsapp" === payload.status && payload.whatsapp_url) {
										showWhatsAppVerification(payload);
										return;
								}
									setStatus("✔ " + (payload.message || ((pkConfig.i18n && pkConfig.i18n.saved) || "Annonce enregistrée !")));
									if (statusEl) statusEl.style.color = "#27ae60";
									window.location.href = payload.url || pkConfig.homeUrl;
							})
							.catch(function (err) {
									submitBtn.disabled    = false;
									submitBtn.textContent = originalText;
									setStatus("✘ " + err.message + " — " + ((pkConfig.i18n && pkConfig.i18n.retry) || "veuillez vérifier vos informations."));
									if (statusEl) statusEl.style.color = "#c0392b";
							});
			});
	}

	function showWhatsAppVerification(payload) {
			if ( ! form) return;

			// Photos refusees par WordPress : on le dit clairement plutot que
			// de livrer une annonce sans images sans explication.
		if (payload.photo_errors && payload.photo_errors.length) {
				var warn          = document.createElement("div");
				warn.className    = "pk-photo-errors";
				var intro         = document.createElement("p");
				intro.textContent = payload.photo_errors.length + " " + ((pkConfig.i18n && pkConfig.i18n.photoError) || "photo(s) n'ont pas pu être ajoutées :");
				warn.appendChild(intro);
				var ul = document.createElement("ul");
				payload.photo_errors.forEach(function (msg) {
						var li         = document.createElement("li");
						li.textContent = msg;
						ul.appendChild(li);
				});
				warn.appendChild(ul);
				var hint         = document.createElement("p");
				hint.textContent = (pkConfig.i18n && pkConfig.i18n.photoHint) || "Vous pourrez les ajouter depuis « Mes annonces » après validation.";
				warn.appendChild(hint);
				form.parentNode.insertBefore(warn, form);
		}
			form.hidden     = true;
			var panel       = document.createElement("section");
			panel.className = "pk-whatsapp-verification";
			panel.tabIndex  = -1;

			var title         = document.createElement("h2");
			title.textContent = (pkConfig.i18n && pkConfig.i18n.whatsappTitle) || "Une dernière étape : WhatsApp";
			var intro         = document.createElement("p");
			intro.textContent = (pkConfig.i18n && pkConfig.i18n.whatsappIntro) || "Votre annonce est enregistrée, mais reste invisible tant que l’équipe n’a pas rapproché votre message WhatsApp.";
			var code          = document.createElement("p");
			code.className    = "pk-whatsapp-code";
			code.textContent  = ((pkConfig.i18n && pkConfig.i18n.whatsappCode) || "Code de validation :") + " " + (payload.verification_code || "—");
			var link          = document.createElement("a");
			link.className    = "pk-btn pk-btn-primary";
			link.href         = payload.whatsapp_url;
			link.target       = "_blank";
			link.rel          = "noopener";
			link.textContent  = (pkConfig.i18n && pkConfig.i18n.whatsappOpen) || "Ouvrir WhatsApp et envoyer le message";
			var note          = document.createElement("p");
			note.className    = "pk-form-note";
			note.textContent  = (pkConfig.i18n && pkConfig.i18n.whatsappNote) || "Conservez ce code. L’annonce sera publiée seulement après vérification manuelle du message par l’équipe pep.ma.";

			panel.appendChild(title);
			panel.appendChild(intro);
			panel.appendChild(code);
			panel.appendChild(link);
			panel.appendChild(note);
			form.parentNode.insertBefore(panel, form);
			panel.focus();
	}

	function setStatus(msg) {
			if (statusEl) statusEl.textContent = msg;
	}

		/* ---------- Tableau de bord proprietaire (mes annonces) ---------- */
	/* SE-044 / DP-9 : parcours unique « Désactiver (motif) » / « Réactiver ». */
		var manageButtons = document.querySelectorAll(".pk-manage-btn");
	if (manageButtons.length && window.pkConfig && pkConfig.manageNonce) {
			/* SE-044 / DP-9 (revue E4, constat 1) : le champ « Précisez » suit le motif
			 * choisi — révélé pour « autre » uniquement, masqué sinon. Le texte saisi est
			 * conservé (non destructif) mais jamais transmis pour un autre motif.
			 * Un seul écouteur « change » par formulaire, posé au chargement. */
			function syncReasonNote(box) {
				var wrap = box ? box.querySelector('[data-for-reason="autre"]') : null;
				if (! wrap) return;
				var checked = box.querySelector('input[type="radio"]:checked');
				var other = !! (checked && "autre" === checked.value);
				wrap.hidden = ! other;
				var field = wrap.querySelector(".pk-reason-note");
				if (field) field.setAttribute("aria-required", other ? "true" : "false");
			}
			document.querySelectorAll(".pk-deactivate-reason").forEach(function (box) {
				box.addEventListener("change", function (e) {
					if (e.target && "radio" === (e.target.type || "")) syncReasonNote(box);
				});
			});
			manageButtons.forEach(function (btn) {
					btn.addEventListener("click", function () {
							var action   = btn.getAttribute("data-action");
							var postId   = btn.getAttribute("data-post-id");
							var item     = btn.closest(".pk-listing-item");
							var feedback = item ? item.querySelector(".pk-listing-feedback") : null;

								/* Désactiver : ouvrir le formulaire de motif (aucun envoi ici). */
								if ("deactivate" === action) {
											var form = item ? item.querySelector('.pk-deactivate-reason[data-post-id="' + postId + '"]') : null;
											if (form) {
													form.hidden = false;
													syncReasonNote(form);
													btn.disabled = true;
													var cancel = form.querySelector(".pk-reason-cancel");
													if (cancel) cancel.addEventListener("click", function () {
															form.hidden = true;
															btn.disabled = false;
													});
											}
								return;
						}

								/* Confirmation : motif obligatoire + note si « autre ». */
								var reason = "";
								var note = "";
								if ("deactivate_confirm" === action) {
											var box = item ? item.querySelector('.pk-deactivate-reason[data-post-id="' + postId + '"]') : null;
											if (! box) return;
											var checked = box.querySelector('input[type="radio"]:checked');
											if (! checked) {
													if (feedback && pkConfig.i18n && pkConfig.i18n.reasonRequired) feedback.textContent = "\u2718 " + pkConfig.i18n.reasonRequired;
													return;
											}
											reason = checked.value;
											if ("autre" === reason) {
													var noteField = box.querySelector(".pk-reason-note");
													note = noteField ? noteField.value.trim() : "";
													if (! note) {
															if (feedback && pkConfig.i18n && pkConfig.i18n.noteRequired) feedback.textContent = "\u2718 " + pkConfig.i18n.noteRequired;
															return;
						}
												}
											action = "deactivate";
								}

								/* Neutralisation du double clic pendant la requête. */
								var allBtns = item ? item.querySelectorAll(".pk-manage-btn") : [];
								allBtns.forEach(function (b) { b.disabled = true; });

							var fd = new FormData();
							fd.append("action", "pk_manage_listing");
							fd.append("nonce", pkConfig.manageNonce);
							fd.append("manage_action", action);
							fd.append("post_id", postId);
								if (reason) fd.append("reason", reason);
								if (note) fd.append("note", note);

							fetch(pkConfig.ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
									.then(function (res) {
											return res.json().then(function (data) {
													if ( ! res.ok) throw new Error((data && data.data && data.data.message) || ((pkConfig.i18n && pkConfig.i18n.serverError) || "Erreur serveur"));
													return data;
											});
									})
											.then(function (data) {
													/* Message du serveur (libellés stockés avec leurs clés — jamais de message trompeur). */
													if (feedback) feedback.textContent = "\u2714 " + ((data && data.data && data.data.message) || "");
												window.setTimeout(function () { window.location.reload(); }, 1200);
									})
									.catch(function (err) {
													allBtns.forEach(function (b) { b.disabled = false; });
													if (feedback) feedback.textContent = "\u2718 " + err.message;
									});
					});
			});
	}
})();
/* Partage natif de la fiche annonce (Web Share API, repli presse-papiers). */
(function () {
		var btn = document.querySelector(".pk-single-share");
		if ( ! btn) return;
		btn.addEventListener("click", function () {
				var url   = btn.getAttribute("data-url") || window.location.href;
				var title = btn.getAttribute("data-title") || document.title;
			if (navigator.share) {
					navigator.share({ title: title, url: url }).catch(function () {});
					return;
			}
			if (navigator.clipboard) {
					navigator.clipboard.writeText(url).then(function () {
							var old = btn.getAttribute("aria-label");
							btn.setAttribute("aria-label", "Lien copié");
							setTimeout(function () { btn.setAttribute("aria-label", old); }, 2000);
					});
			}
		});
})();

/* Bascule grille / liste sur l'archive, memorisee entre les visites. */
(function () {
		var toggle = document.querySelector(".pk-view-toggle");
		var grid   = document.querySelector(".pk-grid-cards");
		if ( ! toggle || ! grid) return;
		var KEY = "pk_view_mode";

	function apply(mode) {
			grid.classList.toggle("pk-grid-list", mode === "list");
			toggle.querySelectorAll(".pk-view-btn").forEach(function (b) {
					var on = b.getAttribute("data-view") === mode;
					b.classList.toggle("is-active", on);
					b.setAttribute("aria-pressed", on ? "true" : "false");
			});
	}
	try { apply(localStorage.getItem(KEY) === "list" ? "list" : "grid"); } catch (e) {}

		toggle.addEventListener("click", function (e) {
				var btn = e.target.closest(".pk-view-btn");
				if ( ! btn) return;
				var mode = btn.getAttribute("data-view");
				apply(mode);
			try { localStorage.setItem(KEY, mode); } catch (err) {}
		});
})();

/* Sélecteur de langue : ouverture/fermeture du menu déroulant. */
(function () {
		var box = document.querySelector("[data-pk-lang]");
		if ( ! box) return;
		var btn  = box.querySelector(".pk-lang-toggle");
		var menu = box.querySelector(".pk-lang-menu");
		if ( ! btn || ! menu) return;

	function close() {
			menu.hidden = true;
			btn.setAttribute("aria-expanded", "false");
	}
		btn.addEventListener("click", function (e) {
				e.stopPropagation();
				var open    = menu.hidden;
				menu.hidden = ! open;
				btn.setAttribute("aria-expanded", open ? "true" : "false");
		});
		document.addEventListener("click", function (e) {
				if ( ! box.contains(e.target)) close();
		});
		document.addEventListener("keydown", function (e) {
				if (e.key === "Escape") close();
		});
})();

/* ---------- Page Favoris ---------- */
(function () {
		"use strict";
		var grid = document.getElementById("pk-favorites-grid");
		if ( ! grid || typeof pkConfig === "undefined") return;

		var empty   = document.getElementById("pk-favorites-empty");
		var countEl = document.getElementById("pk-fav-count");
		var ids     = [];
	try { ids = JSON.parse(window.localStorage.getItem("pk_wishlist") || "[]"); } catch (e) { ids = []; }

	function showEmpty() {
			if (empty) empty.hidden          = false;
			if (countEl) countEl.textContent = "Aucune annonce enregistrée pour l’instant.";
	}

	if ( ! ids.length) { showEmpty(); return; }

		var body = new FormData();
		body.append("action", "pk_favorites_list");
		body.append("nonce", pkConfig.nonce);
		body.append("ids", ids.join(","));

		fetch(pkConfig.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if ( ! data || ! data.success || ! data.data.count) { showEmpty(); return; }
						grid.innerHTML = data.data.html;
					if (countEl) {
							countEl.textContent = data.data.count + (data.data.count > 1 ? " biens enregistrés" : " bien enregistré") + " depuis cet appareil.";
					}
						// Les cartes viennent d'arriver : on rebranche les coeurs.
						if (window.pkBindWishlist) window.pkBindWishlist();
				})
				.catch(showEmpty);
}());


/* Ultra-Premium v1.8 : filtres mobiles et sticky contextuel. */
(function () {
		"use strict";
				var filterToggle   = document.querySelector(".pk-filter-toggle");
				var filterPanel    = document.getElementById("pk-filters-panel");
				var archiveSearch  = document.querySelector(".pk-archive-search .pk-search-archive");
				var archiveTrust   = document.querySelector(".pk-archive-search .pk-archive-trust");
				var filterBackdrop = document.querySelector(".pk-filters-backdrop");
				var filterClose    = document.querySelector(".pk-filter-close");
	if (filterToggle && filterPanel) {
		function isMobileFilter() {
				return window.matchMedia && window.matchMedia("(max-width: 767px)").matches;
		}
		function setFilters(open) {
				var mobile  = isMobileFilter();
				var visible = mobile ? ! ! open : true;
				filterPanel.classList.toggle("is-open", mobile && visible);
				filterPanel.setAttribute("aria-hidden", (mobile && ! visible) ? "true" : "false");
				if ("inert" in filterPanel) filterPanel.inert = mobile && ! visible;
			else if (mobile && ! visible) filterPanel.setAttribute("inert", "");
			else filterPanel.removeAttribute("inert");
				document.body.classList.toggle("pk-filters-open", mobile && visible);
			if (filterBackdrop) {
									filterBackdrop.hidden = ! (mobile && visible);
									filterBackdrop.classList.toggle("is-open", mobile && visible);
			}
				if (archiveSearch) archiveSearch.classList.toggle("is-mobile-filter-open", mobile && visible);
				if (archiveTrust) archiveTrust.classList.toggle("is-mobile-filter-open", mobile && visible);
				filterToggle.setAttribute("aria-expanded", mobile && visible ? "true" : "false");
		}
					var earlyOpen = filterPanel.classList.contains("is-open") && filterPanel.getAttribute("aria-hidden") === "false";
					if (window.pkEarlyFilterClick) document.removeEventListener("click", window.pkEarlyFilterClick, true);
					if (window.pkEarlyFilterKeydown) document.removeEventListener("keydown", window.pkEarlyFilterKeydown, true);
					setFilters(earlyOpen);
					window.addEventListener("resize", function () { setFilters(filterPanel.classList.contains("is-open")); });
					filterToggle.addEventListener("click", function () {
							setFilters( ! filterPanel.classList.contains("is-open"));
					});
					document.querySelectorAll("[data-pk-filter-close]").forEach(function (closeButton) {
							closeButton.addEventListener("click", function () { setFilters(false); });
					});
					document.addEventListener("keydown", function (event) {
							if (event.key === "Escape" && filterPanel.classList.contains("is-open")) setFilters(false);
					});
	}

		var actionBar = document.querySelector(".pk-mobile-action-bar");
		if ( ! actionBar) return;
	function updateKeyboardState() {
			if ( ! window.visualViewport) return;
			var viewport           = window.visualViewport;
			var keyboardLikelyOpen = window.innerHeight - viewport.height > 140;
			actionBar.classList.toggle("is-keyboard-open", keyboardLikelyOpen);
	}
	if (window.visualViewport) {
			window.visualViewport.addEventListener("resize", updateKeyboardState);
			window.visualViewport.addEventListener("scroll", updateKeyboardState);
	}
		document.addEventListener("focusin", function (event) {
				if (/^(INPUT|SELECT|TEXTAREA)$/.test(event.target.tagName)) updateKeyboardState();
		});
		updateKeyboardState();
}());


/* ---------- Autocompletion publique des villes et quartiers ---------- */
(function () {
		"use strict";
		var ajaxUrl = (typeof pkConfig !== "undefined" && pkConfig.ajaxUrl) ? pkConfig.ajaxUrl : "/wp-admin/admin-ajax.php";
		var nonce   = (typeof pkConfig !== "undefined" && pkConfig.placesNonce) ? pkConfig.placesNonce : "";
		var inputs  = Array.prototype.slice.call(document.querySelectorAll("[data-pk-place-input='true']"));
		if ( ! inputs.length) return;

	function debounce(fn, wait) {
			var timer;
			return function () {
					var args = arguments;
					clearTimeout(timer);
					timer = setTimeout(function () { fn.apply(null, args); }, wait);
			};
	}

	function fetchPlaces(query) {
			var language = (typeof pkConfig !== "undefined" && pkConfig.language) ? pkConfig.language : (document.documentElement.lang || "fr");
			var url      = ajaxUrl + "?action=pk_places_search" +
					(nonce ? "&nonce=" + encodeURIComponent(nonce) : "") +
					"&scope=city&q=" + encodeURIComponent(query) + "&lang=" + encodeURIComponent(language);
			return fetch(url, { credentials: "same-origin" })
					.then(function (response) { return response.json(); })
					.then(function (data) { return data && data.success && data.data && data.data.results ? data.data.results : []; })
					.catch(function () { return []; });
	}

	function resolveTypedPlace(input, hidden) {
			/* Le visiteur a tape « casablanca » sans cliquer de proposition : on fige la
				valeur sur le premier resultat du MEME service que le formulaire de depot,
				pour que l'URL envoyee soit un slug de terme, pas du texte libre. */
			var q = (input.value || "").trim();
			if ( ! q || hidden.value) return Promise.resolve(false);
			return fetchPlaces(q).then(function (results) {
					if ( ! results || ! results.length) return false;
					hidden.value = results[0].value || "";
					input.value  = results[0].label || results[0].city || input.value;
					return true;
			});
	}

		inputs.forEach(function (input) {
				/* Le meme composant sert au hero, au filtre de l'archive et a la barre du
					header : le conteneur peut etre .pk-place-autocomplete OU la barre elle-meme
					(.pk-search-bar). Sans ce second appui, la barre du haut restait muette et
					l'autocomplete n'apparaissait que sur deux pages sur trois. */
				var wrapper = input.closest(".pk-place-autocomplete") || input.closest(".pk-search-bar");
				var list    = (wrapper && wrapper.querySelector(".pk-place-suggestions"))
						|| (input.id ? document.getElementById(input.id + "-list") : null)
						|| (input.getAttribute("aria-controls") ? document.getElementById(input.getAttribute("aria-controls")) : null);
				var valueId = input.getAttribute("data-pk-place-value");
				var hidden  = valueId ? document.getElementById(valueId) : (wrapper ? wrapper.querySelector("input[type='hidden'][name='es_city']") : null);
				if ( ! list || ! hidden) return;
				var combobox = input.getAttribute("role") === "combobox" ? input : null;
			function setExpanded(open) {
					if (combobox) combobox.setAttribute("aria-expanded", open ? "true" : "false");
			}

			function close() { list.hidden = true; list.innerHTML = ""; setExpanded(false); }
			function choose(item) {
					var label    = item.label || item.city || "";
					input.value  = item.district ? label + ", " + (item.meta || item.city || "") : label;
					hidden.value = item.value || "";
					input.setAttribute("aria-activedescendant", "");
					close();
			}
			function render(results) {
					list.innerHTML = "";
				if ( ! results.length) { close(); return; }
					results.forEach(function (item, index) {
							var option       = document.createElement("li");
							option.className = "pk-suggest-item";
							option.setAttribute("role", "option");
							option.id         = input.id + "-suggestion-" + index;
							option.tabIndex   = 0;
							var label         = document.createElement("span");
							label.className   = "pk-suggest-label";
							label.textContent = item.label || item.city || "";
							var meta          = document.createElement("span");
							meta.className    = "pk-suggest-meta";
							meta.textContent  = item.meta || "";
							option.appendChild(label);
							option.appendChild(meta);
							option.addEventListener("mousedown", function (event) { event.preventDefault(); choose(item); });
							option.addEventListener("keydown", function (event) {
								if (event.key === "Enter" || event.key === " ") { event.preventDefault(); choose(item); }
							});
							list.appendChild(option);
					});
					list.hidden = false;
					if (input.closest(".pk-filters-panel") && input.scrollIntoView) {
						try { input.scrollIntoView({ block: "nearest", inline: "nearest" }); } catch (err) {}
					}
			}
				var suggest = debounce(function () {
						hidden.value = "";
						var query    = input.value.trim();
					if ( ! query) { close(); return; }
						fetchPlaces(query).then(render);
				}, 120);
				input.addEventListener("input", suggest);
				input.addEventListener("focus", function () {
						if (input.value.trim()) suggest();
				});
				input.addEventListener("keydown", function (event) {
					if (event.key === "Escape") { close(); return; }
						/* Fleches: le visiteur choisit dans la liste sans quitter le clavier,
							comme dans le formulaire de depot. */
						var items = Array.prototype.slice.call(list.querySelectorAll(".pk-suggest-item"));
						if ( ! items.length) return;
					if (event.key === "ArrowDown" || event.key === "ArrowUp") {
							event.preventDefault();
							var cur  = items.indexOf(document.activeElement);
							var next = event.key === "ArrowDown" ? Math.min(cur + 1, items.length - 1) : Math.max(cur - 1, 0);
							items[next].focus();
					}
				});
				input.addEventListener("blur", function () { setTimeout(close, 180); });
				var form = input.form;
			if (form) {
					var enResol = false;
					/* resolveTypedPlace rend false quand le service ne trouve rien : la
						resoumission relancait alors la resolution indefiniment (soumission ->
						fetch vide -> resoumission -> ...). On memorise qu'une valeur a deja
						ete resolue sans resultat, et on reinitialise des que le visiteur retape. */
					var resolu = false;
					input.addEventListener("input", function () {
							resolu         = false;
							input.disabled = false;
					});
					form.addEventListener("submit", function (event) {
							if (hidden.value) {
								input.disabled = true;
								return;
							}
							if ( ! (input.value || "").trim() || enResol) return;
							/* Deja resolu sans resultat pour cette valeur : on laisse le
								formulaire partir tel quel (s=... : le serveur traduit le texte
								en ville, ou retombe sur la recherche plein texte). */
							if (resolu) return;
							enResol = true;
							event.preventDefault();
							resolveTypedPlace(input, hidden).then(function () {
									enResol = false;
								if (hidden.value) {
										/* es_city porte la valeur : le champ visible (name="s") ne doit
											pas partir AUSSI en recherche plein texte — sans ca, la requete
											combine filtre ville ET texte, et rend moins de fiches que
											l'ecran n'en promet. Un champ disabled n'est pas soumis. */
										input.disabled = true;
								} else {
										resolu = true;
								}
									if (typeof form.requestSubmit === "function") form.requestSubmit(); else form.submit();
							});
					});
			}
		});
}());


/* ============================================================
	6.17.33 — Page « Connexion » : écrans d'authentification.
	La feuille JS publique d'Estatik (public.min.js) n'est pas
	chargée par le thème (assets retirés pour performance) : on
	reproduit ici les deux comportements dont la page a besoin,
	en JS natif — exactement comme le fait le plugin :
	1. bascule entre écrans (accueil, formulaire, inscription,
		réinitialisation) via les liens [data-auth-item] ;
	2. activation des boutons « Log in » / « Sign up » dès que
		leurs champs obligatoires sont remplis (le HTML les livre
		désactivés pour éviter les soumissions vides).
	============================================================ */
(function () {
		var authCard = document.querySelector(".pk-auth-card .es-auth");
		if ( ! authCard) return;

		/* 1. Bascule d'écrans. Les conteneurs portent la classe
			.es-auth__{item} (es-auth__login-form…) et .es-auth__item ;
			on masque tout sauf l'écran demandé. */
		authCard.addEventListener("click", function (event) {
				var link = event.target.closest ? event.target.closest("[data-auth-item]") : null;
				if ( ! link || ! authCard.contains(link)) return;
				var wanted = "es-auth__" + link.getAttribute("data-auth-item");
				if ( ! authCard.querySelector("." + wanted)) return;
				event.preventDefault();
				authCard.querySelectorAll(".es-auth__item").forEach(function (el) {
						el.classList.toggle("es-auth__item--hidden", ! el.classList.contains(wanted));
				});
				/* Accessibilité : le focus suit l'écran affiché (les liens
					deviennent inertiels une fois masqués). */
				var shown     = authCard.querySelector("." + wanted + ":not(.es-auth__item--hidden)");
				var focusable = shown && shown.querySelector("input, button, a");
				if (focusable) focusable.focus();
		});

		/* 2. Activation conditionnelle des boutons de soumission. */
	function syncButton(form, button, names) {
			if ( ! form || ! button) return;
			var ok = names.every(function (n) {
					var field = form.querySelector('[name="' + n + '"]');
					return field && field.value.length > 0;
			});
			/* Le bouton reste désactivé tant qu'un champ manque ; on
				n'utilise pas removeAttribute pour conserver un état
				contrôlable par le lecteur d'écran. */
			button.disabled = ! ok;
	}

		var loginForm = authCard.querySelector(".js-es-auth__login-form");
		var loginBtn  = loginForm && loginForm.querySelector(".js-es-btn--login");
	if (loginForm && loginBtn) {
			loginForm.addEventListener("input", function () {
					syncButton(loginForm, loginBtn, ["es_user_login", "es_user_password"]);
			});
			syncButton(loginForm, loginBtn, ["es_user_login", "es_user_password"]);
	}

		var signupForm = authCard.querySelector(".js-es-auth__buyer-register-form, .es-auth__buyer-register-form");
		var signupBtn  = signupForm && signupForm.querySelector(".es-btn--signup");
	if (signupForm && signupBtn) {
			signupForm.addEventListener("input", function () {
					syncButton(signupForm, signupBtn, ["es_user_email", "es_user_password"]);
			});
			syncButton(signupForm, signupBtn, ["es_user_email", "es_user_password"]);
	}
}());

/* Accueil mobile : sticky Déposer/Rechercher seulement quand les vrais
   boutons du hero sont ENTIÈREMENT au-dessus du viewport (bottom <= 0).
   IntersectionObserver + overflow:hidden du hero faussait isIntersecting
   → 4 boutons à l’écran. */
(function () {
	var bar = document.querySelector(".pk-mobile-sticky-cta");
	var hero = document.querySelector(".pk-editorial-actions");
	if (!bar || !hero) return;
	function tick() {
		document.body.classList.toggle("pk-sticky-cta-on", hero.getBoundingClientRect().bottom <= 0);
	}
	window.addEventListener("scroll", tick, { passive: true });
	window.addEventListener("resize", tick);
	tick();
}());

/* Annonces mobile : fond noir seulement quand la barre Vente/Location
   (ou Longue durée/Saisonnière) est réellement collée en haut. */
(function () {
	var bar = document.querySelector(".pk-intent-bar");
	var sentinel = document.querySelector(".pk-intent-sentinel");
	if (!bar || !sentinel) return;
	function setStuck(on) {
		document.body.classList.toggle("pk-intent-stuck", !!on);
	}
	if ("IntersectionObserver" in window) {
		var io = new IntersectionObserver(function (entries) {
			var e = entries[0];
			setStuck(e && !e.isIntersecting);
		}, { threshold: 0, rootMargin: "0px" });
		io.observe(sentinel);
		return;
	}
	function tick() {
		setStuck(sentinel.getBoundingClientRect().bottom < 0);
	}
	window.addEventListener("scroll", tick, { passive: true });
	tick();
}());

/* Mobile : retirer Accueil/Annonces/Déposer/Favoris/Connexion du flux
   (v1.8 les réaffichait). Desktop/tablette inchangés.
   Annonces : cacher titre/compteur (CSS cache parfois stale) et montrer Vente/Location. */
(function () {
	if (!window.matchMedia) return;
	var mq = window.matchMedia("(max-width: 767px)");
	var nav = document.querySelector(".pk-main-nav");
	var head = document.querySelector(".pk-archive-head");
	var crumbs = document.querySelector(".pk-archive > .pk-container > .pk-breadcrumb");
	var search = document.querySelector(".pk-archive-search");
	var intent = document.querySelector(".pk-intent-bar");
	function sync() {
		var mobile = mq.matches;
		if (nav) {
			nav.hidden = mobile;
			nav.setAttribute("aria-hidden", mobile ? "true" : "false");
		}
		if (head) head.hidden = false;
		if (crumbs) crumbs.hidden = mobile;
		if (search) search.hidden = false;
		if (intent) {
			intent.hidden = true;
			intent.style.display = "none";
		}
	}
	sync();
	if (mq.addEventListener) mq.addEventListener("change", sync);
	else if (mq.addListener) mq.addListener(sync);
}());


/* Annonces : selects du moteur = liste dans la carte (pas le menu OS). */
(function () {
	"use strict";
	var root = document.querySelector(".pk-archive-search");
	if (!root) return;
	function closeAll(except) {
		root.querySelectorAll(".pk-select.is-open").forEach(function (wrap) {
			if (wrap === except) return;
			wrap.classList.remove("is-open");
			var list = wrap.querySelector(".pk-select-list");
			var btn = wrap.querySelector(".pk-select-btn");
			if (list) list.hidden = true;
			if (btn) btn.setAttribute("aria-expanded", "false");
		});
	}
	root.querySelectorAll("select").forEach(function (select) {
		if (select.closest(".pk-select") || select.classList.contains("pk-find-type-native")) return;
		var wrap = document.createElement("div");
		wrap.className = "pk-select";
		select.parentNode.insertBefore(wrap, select);
		wrap.appendChild(select);
		select.classList.add("pk-select-native");
		var canType = select.hasAttribute("data-pk-min") || select.hasAttribute("data-pk-typein");
		if (canType) wrap.classList.add("pk-select-can-type");
		var btn = document.createElement("button");
		btn.type = "button";
		btn.className = "pk-select-btn";
		btn.setAttribute("aria-haspopup", "listbox");
		btn.setAttribute("aria-expanded", "false");
		var typeField = null;
		function digitsOf(raw) {
			return String(raw || "").replace(/[\u0660-\u0669]/g, function (d) {
				return String(d.charCodeAt(0) - 0x0660);
			}).replace(/[\u06F0-\u06F9]/g, function (d) {
				return String(d.charCodeAt(0) - 0x06F0);
			}).replace(/[^\d]/g, "");
		}
		function groupDigits(n) {
			return String(n || "").replace(/\B(?=(\d{3})+(?!\d))/g, " ");
		}
		function currentLabel() {
			var opt = select.options[select.selectedIndex];
			return opt ? opt.textContent.replace(/^\s+|\s+$/g, "") : "";
		}
		function showTyped() {
			if (!typeField) return;
			typeField.value = select.value ? groupDigits(digitsOf(select.value)) : "";
		}
		function ensureTyped(n) {
			if (!n) {
				select.value = "";
				return;
			}
			if (!select.querySelector("option[value='" + n + "']")) {
				var opt = document.createElement("option");
				opt.value = n;
				opt.textContent = groupDigits(n);
				select.appendChild(opt);
			}
			select.value = n;
		}
		if (canType) {
			typeField = document.createElement("input");
			typeField.type = "text";
			typeField.className = "pk-select-type";
			typeField.setAttribute("inputmode", "numeric");
			typeField.setAttribute("autocomplete", "off");
			typeField.setAttribute("dir", "ltr");
			var emptyOpt = select.options[0];
			var hint = select.getAttribute("data-pk-hint") || "";
			typeField.placeholder = hint || (emptyOpt ? emptyOpt.textContent.replace(/^\s+|\s+$/g, "") : "");
			showTyped();
			btn.classList.add("pk-select-opener");
			btn.textContent = "";
			var labelEl = wrap.parentNode;
			var span = labelEl && labelEl.querySelector ? labelEl.querySelector("span") : null;
			if (span && span.textContent) btn.setAttribute("aria-label", span.textContent.replace(/^\s+|\s+$/g, ""));
		} else {
			btn.textContent = currentLabel();
		}
		var list = document.createElement("ul");
		list.className = "pk-select-list";
		list.hidden = true;
		list.setAttribute("role", "listbox");
		function paintChoice() {
			if (typeField) showTyped();
			else btn.textContent = currentLabel();
		}
		function rebuild() {
			list.textContent = "";
			Array.prototype.forEach.call(select.options, function (opt, index) {
				var li = document.createElement("li");
				li.className = "pk-select-option";
				li.setAttribute("role", "option");
				li.textContent = opt.textContent.replace(/^\s+|\s+$/g, "");
				if (opt.selected) li.setAttribute("aria-selected", "true");
				li.addEventListener("click", function () {
					select.selectedIndex = index;
					try { select.dispatchEvent(new Event("change", { bubbles: true })); }
					catch (err) { /* IE */ }
					paintChoice();
					closeAll();
				});
				list.appendChild(li);
			});
		}
		rebuild();
		select.addEventListener("pk-select-rebuild", function () {
			rebuild();
			paintChoice();
		});
		btn.addEventListener("click", function (event) {
			event.preventDefault();
			event.stopPropagation();
			var willOpen = list.hidden;
			closeAll();
			if (willOpen) {
				rebuild();
				list.hidden = false;
				wrap.classList.add("is-open");
				btn.setAttribute("aria-expanded", "true");
			}
		});
		if (typeField) {
			typeField.addEventListener("input", function () {
				ensureTyped(digitsOf(typeField.value));
				try { select.dispatchEvent(new Event("change", { bubbles: true })); }
				catch (err) { /* IE */ }
			});
			typeField.addEventListener("blur", showTyped);
			wrap.appendChild(typeField);
			var unit = select.getAttribute("data-pk-unit") || "";
			if (unit) {
				var unitEl = document.createElement("span");
				unitEl.className = "pk-select-unit";
				unitEl.setAttribute("dir", "ltr");
				unitEl.textContent = unit;
				wrap.appendChild(unitEl);
			}
		}
		wrap.appendChild(btn);
		wrap.appendChild(list);
	});
	document.addEventListener("click", function (event) {
		if (event.target.closest && event.target.closest(".pk-select")) return;
		closeAll();
	});
}());


/* Budget max : paliers vente ≠ location, dès le changement d’action. */
(function () {
	"use strict";
	var action = document.getElementById("pk-s-action");
	var budget = document.getElementById("pk-s-budget");
	if (!action || !budget) return;
	function parseList(attr) {
		try { return JSON.parse(budget.getAttribute(attr) || "[]"); }
		catch (err) { return []; }
	}
	var sale = parseList("data-pk-sale");
	var rent = parseList("data-pk-rent");
	var unlimited = budget.getAttribute("data-pk-unlimited") || "Illimité";
	var suffixSale = budget.getAttribute("data-pk-suffix-sale") || " DH";
	var suffixRent = budget.getAttribute("data-pk-suffix-rent") || " DH / mois";
	function formatNumber(n) {
		try { return Number(n).toLocaleString("fr-FR"); }
		catch (err) { return String(n); }
	}
	function fill(values, suffix) {
		var current = budget.value;
		while (budget.firstChild) budget.removeChild(budget.firstChild);
		var empty = document.createElement("option");
		empty.value = "";
		empty.textContent = unlimited;
		budget.appendChild(empty);
		values.forEach(function (n) {
			var opt = document.createElement("option");
			opt.value = String(n);
			opt.textContent = formatNumber(n) + suffix;
			budget.appendChild(opt);
		});
		var keep = false;
		Array.prototype.forEach.call(budget.options, function (opt) {
			if (opt.value === current) keep = true;
		});
		budget.value = keep ? current : "";
		try { budget.dispatchEvent(new Event("pk-select-rebuild")); }
		catch (err) { /* ignore */ }
	}
	function sync() {
		var isRent = action.value === "a-louer";
		fill(isRent ? rent : sale, isRent ? suffixRent : suffixSale);
	}
	action.addEventListener("change", sync);
}());


/* Première visite : mémoriser le choix de langue (header ou popup). */
(function () {
	"use strict";
	function chosen() {
		try {
			if (window.localStorage && localStorage.getItem("pk_lang_gate_v2") === "1") return true;
		} catch (err) { /* private mode */ }
		return /(?:^|; )pk_lang_gate_v2=1(?:;|$)/.test(document.cookie);
	}
	function setChosen() {
		document.cookie = "pk_lang_gate_v2=1;path=/;max-age=31536000;SameSite=Lax";
		try { if (window.localStorage) localStorage.setItem("pk_lang_gate_v2", "1"); } catch (err) { /* ignore */ }
	}
	var gate = document.querySelector("[data-pk-lang-gate]");
	if (gate) {
		if (chosen()) {
			gate.hidden = true;
		} else {
			gate.hidden = false;
			document.body.classList.add("pk-lang-gate-open");
			function dismiss() {
				setChosen();
				gate.hidden = true;
				document.body.classList.remove("pk-lang-gate-open");
			}
			gate.querySelectorAll("[data-pk-lang-pick]").forEach(function (link) {
				link.addEventListener("click", setChosen);
			});
			var closeBtn = gate.querySelector("[data-pk-lang-dismiss]");
			if (closeBtn) closeBtn.addEventListener("click", dismiss);
			gate.addEventListener("click", function (e) { if (e.target === gate) dismiss(); });
			document.addEventListener("keydown", function (e) {
				if (e.key === "Escape" && !gate.hidden) dismiss();
			});
			var focusTarget = closeBtn || gate.querySelector("[data-pk-lang-pick]");
			if (focusTarget && typeof focusTarget.focus === "function") {
				try { focusTarget.focus(); } catch (err) { /* ignore */ }
			}
		}
	}
	document.querySelectorAll("[data-pk-lang] a").forEach(function (link) {
		link.addEventListener("click", setChosen);
	});
}());

/* Barre de recherche : curseurs, onglet location, second clic, fermeture. */
(function () {
	"use strict";
	var root = document.querySelector(".pk-find");
	if (!root) return;

	function group(n) {
		return String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
	}
	function syncRange(box) {
		var min = box.querySelector(".pk-find-range-min");
		var max = box.querySelector(".pk-find-range-max");
		var fill = box.querySelector(".pk-find-fill");
		var out = box.querySelector(".pk-find-readout");
		if (!min || !max) return;
		var lo = Number(min.value);
		var hi = Number(max.value);
		if (lo > hi) {
			if (document.activeElement === min) { max.value = String(lo); hi = lo; }
			else { min.value = String(hi); lo = hi; }
		}
		var scaleMin = Number(min.min || 0);
		var scaleMax = Number(min.max || 1);
		var span = Math.max(1, scaleMax - scaleMin);
		var start = ((lo - scaleMin) / span) * 100;
		var width = ((hi - lo) / span) * 100;
		if (fill) {
			fill.style.left = start + "%";
			fill.style.width = Math.max(0, width) + "%";
		}
		if (out) {
			var unit = box.getAttribute("data-unit") || "";
			out.textContent = group(lo) + " – " + group(hi) + (unit ? " " + unit : "");
		}
	}
	function priceMode() {
		var input = root.querySelector(".pk-find-tab-input:checked");
		var value = input ? input.value : "a-vendre";
		if (value === "saisonnier") return "season";
		if (value === "a-louer") return "rent";
		return "sale";
	}
	function setPriceScale(mode, keep) {
		var box = root.querySelector("[data-pk-price]");
		if (!box) return;
		var min = box.querySelector("[data-pk-min]");
		var max = box.querySelector("[data-pk-max]");
		if (!min || !max) return;
		var sale = mode === "sale";
		var list = (sale ? box.getAttribute("data-sale-prices") : box.getAttribute("data-rent-prices")) || "";
		var unit = mode === "rent" ? (box.getAttribute("data-rent-unit") || "") : (mode === "season" ? (box.getAttribute("data-season-unit") || "") : (box.getAttribute("data-sale-unit") || ""));
		var cap = Number(sale ? box.getAttribute("data-sale-max") : box.getAttribute("data-rent-max"));
		var any = box.getAttribute("data-any") || "";
		function fill(select) {
			var current = keep ? select.value : "";
			if (keep && select.options.length) return;
			select.innerHTML = "";
			var empty = document.createElement("option");
			empty.value = "";
			empty.textContent = any;
			select.appendChild(empty);
			list.split(",").forEach(function (raw) {
				var n = Number(raw);
				if (!n || (cap && n >= cap)) return;
				var opt = document.createElement("option");
				opt.value = String(n);
				opt.textContent = group(n) + (unit ? " " + unit : "");
				select.appendChild(opt);
			});
			select.value = current && select.querySelector("option[value='" + current + "']") ? current : "";
			if (!select.value && current && /^[0-9]+$/.test(current)) {
				var typed = Number(current);
				if (typed > 0 && (!cap || typed < cap)) {
					var extra = document.createElement("option");
					extra.value = String(typed);
					extra.textContent = group(typed) + (unit ? " " + unit : "");
					select.appendChild(extra);
					select.value = String(typed);
				}
			}
			try { select.dispatchEvent(new Event("pk-select-rebuild")); }
			catch (err) { /* IE */ }
		}
		fill(min);
		fill(max);
	}
	function currentFamily() {
		if (!typeSelect || !typeSelect.value) return "";
		var chosen = typeSelect.options[typeSelect.selectedIndex];
		return chosen ? (chosen.getAttribute("data-family") || "") : "";
	}
	function txCode() {
		var mode = priceMode();
		if (mode === "season") return "saisonnier";
		if (mode === "rent") return "louer";
		return "vente";
	}
	function hasToken(list, token) {
		return (" " + (list || "") + " ").indexOf(" " + token + " ") !== -1;
	}
	function syncHiddenIntent() {
		var intent = root.querySelector(".pk-find-tab-input:checked");
		var action = root.querySelector("input[name='es_action']");
		var rent = root.querySelector("input[name='pk_rent']");
		var value = intent ? intent.value : "a-vendre";
		if (action) action.value = value === "a-vendre" ? "a-vendre" : "a-louer";
		if (rent) rent.value = value === "saisonnier" ? "saisonnier" : "";
	}
	function applyContext(keepPrice) {
		syncHiddenIntent();
		setPriceScale(priceMode(), keepPrice);
		syncTxButtons();
		syncTypeCards();
		var code = txCode();
		var slug = "";
		if (typeSelect && typeSelect.value) slug = String(typeSelect.value).replace(/-(fr|en|ar)$/, "");
		root.querySelectorAll(".pk-find-chip").forEach(function (chip) {
			var show = hasToken(chip.getAttribute("data-pk-tx"), code) && hasToken(chip.getAttribute("data-pk-types"), slug);
			chip.hidden = !show;
			var box = chip.querySelector("input");
			if (!box) return;
			box.disabled = !show;
			if (!show && box.getAttribute("data-pk-default") !== "1") box.checked = false;
		});
		var rooms = root.querySelector("[data-pk-rooms]");
		if (rooms) {
			var housing = (rooms.getAttribute("data-housing") || "").split(" ");
			var showRooms = !slug || housing.indexOf(slug) !== -1;
			rooms.hidden = !showRooms;
			if (!showRooms) {
				rooms.querySelectorAll("input").forEach(function (input) {
					input.checked = false;
					input.removeAttribute("data-was");
				});
			}
		}
		var amenities = root.querySelector(".pk-find-amenities");
		if (amenities) amenities.hidden = !amenities.querySelector(".pk-find-chip:not([hidden])");
		guardFilters();
	}
	function syncTypeCards() {
		var intent = root.querySelector(".pk-find-tab-input:checked");
		var value = intent ? intent.value : "a-vendre";
		var token = value === "saisonnier" ? "saisonnier" : (value === "a-louer" ? "louer" : "vente");
		function allow(list) {
			return (" " + (list || "vente louer") + " ").indexOf(" " + token + " ") !== -1;
		}
		root.querySelectorAll(".pk-find-type-opt[data-pk-tx], .pk-find-type-card[data-pk-tx]").forEach(function (card) {
			var slug = card.getAttribute("data-value") || "";
			if (!slug) {
				card.hidden = false;
				return;
			}
			var show = allow(card.getAttribute("data-pk-tx"));
			card.hidden = !show;
			if (!show && typeSelect && typeSelect.value === slug) {
				typeSelect.value = "";
				syncTypeLabel();
			}
		});
		if (typeSelect) {
			Array.prototype.forEach.call(typeSelect.options, function (opt) {
				if (!opt.value) {
					opt.hidden = false;
					opt.disabled = false;
					return;
				}
				var show = allow(opt.getAttribute("data-pk-tx"));
				opt.hidden = !show;
				opt.disabled = !show;
				if (!show && typeSelect.value === opt.value) {
					typeSelect.value = "";
					syncTypeLabel();
				}
			});
		}
	}
	function syncTxButtons() {
		var chosen = root.querySelector(".pk-find-tab-input:checked");
		var value = chosen ? chosen.value : "";
		root.querySelectorAll(".pk-find-tx-btn").forEach(function (btn) {
			var on = btn.getAttribute("data-pk-intent") === value;
			btn.classList.toggle("is-on", on);
			btn.setAttribute("aria-pressed", on ? "true" : "false");
		});
	}
	root.querySelectorAll(".pk-find-tx-btn").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var value = btn.getAttribute("data-pk-intent") || "";
			var input = root.querySelector(".pk-find-tab-input[value='" + value + "']");
			if (!input) return;
			input.checked = true;
			applyContext(false);
		});
	});
	function intentChosen() {
		return !!root.querySelector(".pk-find-tab-input:checked");
	}
	function typeChosen() {
		return !!(typeSelect && typeSelect.value);
	}
	function filtersReady() {
		return intentChosen() && typeChosen();
	}
	function showNeed(on) {
		var note = root.querySelector(".pk-find-need");
		if (note) note.hidden = !on;
		root.classList.toggle("is-need-tx", !!on && !intentChosen());
		root.classList.toggle("is-need-type", !!on && !typeChosen());
	}
	function guardFilters() {
		root.classList.remove("is-filter-locked");
		var filter = root.querySelector(".pk-find-filter");
		if (filter) filter.removeAttribute("aria-disabled");
		if (filtersReady()) showNeed(false);
	}
	root.querySelectorAll(".pk-find-seg input").forEach(function (input) {
		input.addEventListener("click", function () {
			if (input.getAttribute("data-was") === "1") {
				input.checked = false;
				input.removeAttribute("data-was");
				return;
			}
			root.querySelectorAll("input[name='" + input.name + "']").forEach(function (other) {
				other.removeAttribute("data-was");
			});
			input.setAttribute("data-was", "1");
		});
	});
	root.querySelectorAll(".pk-find-duo").forEach(function (duo) {
		var min = duo.querySelector("select:first-of-type");
		var max = duo.querySelector("select:last-of-type");
		if (!min || !max) return;
		duo.addEventListener("change", function () {
			if (!min.value || !max.value) return;
			if (Number(min.value) > Number(max.value)) {
				if (document.activeElement === min) max.value = min.value;
				else min.value = max.value;
			}
		});
	});
	root.addEventListener("submit", function () {
		root.querySelectorAll(".pk-find-panel select").forEach(function (select) {
			if (!select.value) select.disabled = true;
		});
		root.querySelectorAll("input[name='es_bedrooms_min'], input[name='pk_salons_min']").forEach(function (input) {
			if (!input.value || (input.closest("[hidden]"))) input.disabled = true;
		});
		root.querySelectorAll(".pk-find-chip[hidden] input, [data-pk-rooms][hidden] input").forEach(function (input) {
			input.disabled = true;
		});
		var rent = root.querySelector("input[name='pk_rent']");
		if (rent && !rent.value) rent.disabled = true;
	});
	root.querySelectorAll(".pk-find-tab-input").forEach(function (input) {
		input.addEventListener("change", function () {
			applyContext(false);
			if (root.getAttribute("data-pk-live") === "archive") {
				if (typeof root.requestSubmit === "function") root.requestSubmit();
				else root.submit();
			}
		});
	});
	root.querySelectorAll(".pk-find-pill input[value]").forEach(function (input) {
		if (!input.value) return;
		input.setAttribute("data-pk-on", input.checked ? "1" : "0");
		input.addEventListener("click", function () {
			if (input.getAttribute("data-pk-on") === "1") {
				var none = root.querySelector("input[name='" + input.name + "'][value='']");
				if (none) none.checked = true;
				input.checked = false;
				input.setAttribute("data-pk-on", "0");
			}
		});
		input.addEventListener("change", function () {
			root.querySelectorAll("input[name='" + input.name + "']").forEach(function (other) {
				other.setAttribute("data-pk-on", other.checked && other.value ? "1" : "0");
			});
		});
	});
	var clearer = root.querySelector(".pk-find-clear");
	if (clearer) {
		clearer.addEventListener("click", function (event) {
			event.preventDefault();
			var type = root.querySelector("[name='es_type']");
			if (type) {
				type.value = "";
				if (typeof syncTypeLabel === "function") syncTypeLabel();
			}
			var city = root.querySelector("[data-pk-place-input]");
			var cityValue = document.getElementById("pk-s-city-value");
			if (city) city.value = "";
			if (cityValue) cityValue.value = "";
			root.querySelectorAll(".pk-find-chip input").forEach(function (box) { box.checked = false; });
			root.querySelectorAll(".pk-find-pill input, input[name='es_bedrooms_min'], input[name='pk_salons_min']").forEach(function (box) {
				box.checked = box.value === "";
				box.setAttribute("data-pk-on", "0");
			});
			var sale = root.querySelector(".pk-find-tab-input:checked");
			setPriceScale(sale && sale.value === "a-louer", false);
			var area = root.querySelector("[data-pk-area]");
			if (area) {
				var amin = area.querySelector(".pk-find-range-min");
				var amax = area.querySelector(".pk-find-range-max");
				if (amin) amin.value = amin.min || "0";
				if (amax) amax.value = amax.max;
				syncRange(area);
			}
			if (typeof root.requestSubmit === "function") root.requestSubmit();
			else root.submit();
		});
	}
	var typeSelect = root.querySelector(".pk-find-type-native");
	var typeBtn = root.querySelector(".pk-find-type-btn");
	var typeMenu = root.querySelector(".pk-find-type-menu");
	var typeBackdrop = root.querySelector(".pk-find-type-backdrop");
	function syncTypeLabel() {
		if (!typeSelect) return;
		var current = root.querySelector(".pk-find-type-current");
		var chosen = typeSelect.options[typeSelect.selectedIndex];
		if (current && chosen) current.textContent = chosen.textContent.replace(/^\s+|\s+$/g, "");
		root.querySelectorAll(".pk-find-type-opt").forEach(function (opt) {
			opt.setAttribute("aria-selected", opt.getAttribute("data-value") === typeSelect.value ? "true" : "false");
		});
	}
	function closeType() {
		root.classList.remove("is-type-open");
		if (typeBtn) typeBtn.setAttribute("aria-expanded", "false");
		if (typeMenu) typeMenu.hidden = true;
		if (typeBackdrop) typeBackdrop.hidden = true;
	}
	function placeTypeMenu() {
		if (!typeMenu || typeMenu.hidden) return;
		var header = document.querySelector(".pk-site-header");
		if (header) document.documentElement.style.setProperty("--pk-header-h", header.offsetHeight + "px");
		if (window.matchMedia("(max-width: 1100px)").matches) return;
		typeMenu.style.insetInlineStart = "0";
		typeMenu.style.insetInlineEnd = "auto";
		var rect = typeMenu.getBoundingClientRect();
		if (rect.right > window.innerWidth - 12 || rect.left < 12) {
			typeMenu.style.insetInlineStart = "auto";
			typeMenu.style.insetInlineEnd = "0";
		}
	}
	function openType() {
		if (opener && opener.checked) {
			opener.checked = false;
			setExpanded();
		}
		root.classList.add("is-type-open");
		if (typeBtn) typeBtn.setAttribute("aria-expanded", "true");
		if (typeMenu) typeMenu.hidden = false;
		if (typeBackdrop) typeBackdrop.hidden = false;
		placeTypeMenu();
	}
	if (typeSelect) typeSelect.addEventListener("change", function () { applyContext(true); });
	if (typeSelect && typeBtn && typeMenu) {
		root.classList.add("is-type");
		syncTypeLabel();
		typeBtn.addEventListener("click", function (event) {
			event.preventDefault();
			event.stopPropagation();
			if (root.classList.contains("is-type-open")) closeType();
			else openType();
		});
		root.querySelectorAll(".pk-find-type-opt").forEach(function (opt) {
			opt.addEventListener("click", function () {
				var value = opt.getAttribute("data-value") || "";
				if (opt.classList.contains("pk-find-type-card") && typeSelect.value === value) value = "";
				typeSelect.value = value;
				syncTypeLabel();
				closeType();
				applyContext(true);
			});
		});
		if (typeBackdrop) typeBackdrop.addEventListener("click", closeType);
		window.addEventListener("resize", placeTypeMenu);
	}

	var opener = root.querySelector(".pk-find-open");
	function setExpanded() {
		if (opener) opener.setAttribute("aria-expanded", opener.checked ? "true" : "false");
		if (opener && opener.checked) closeType();
		document.documentElement.classList.toggle("pk-find-modal", !!(opener && opener.checked));
	}
	setExpanded();
	var filterBtn = root.querySelector(".pk-find-filter");
	if (filterBtn) {
		filterBtn.addEventListener("click", function () {
			showNeed(false);
		});
	}
	var closeBtn = root.querySelector(".pk-find-close");
	if (closeBtn) {
		closeBtn.addEventListener("click", function () {
			if (opener) opener.checked = false;
			setExpanded();
			showNeed(false);
		});
	}
	if (opener) opener.addEventListener("change", setExpanded);
	applyContext(true);
	document.addEventListener("keydown", function (event) {
		if (event.key === "Escape") {
			if (opener && opener.checked) opener.checked = false;
			closeType();
		}
		setExpanded();
	});
	document.addEventListener("click", function (event) {
		if (!opener || !opener.checked) return;
		if (root.contains(event.target)) return;
		opener.checked = false;
		setExpanded();
	});

	function formatCount(n) {
		var s = String(Math.max(0, n | 0));
		return s.replace(/\B(?=(\d{3})+(?!\d))/g, " ");
	}
	function paintCount(n) {
		var btn = root.querySelector(".pk-find-results");
		if (!btn) return;
		var num = btn.querySelector(".pk-find-results-num");
		var noun = btn.querySelector(".pk-find-results-noun");
		var one = btn.getAttribute("data-one") || "";
		var many = btn.getAttribute("data-many") || "";
		var label = n === 1 ? one : many;
		btn.setAttribute("data-pk-count", String(n));
		if (num) num.textContent = formatCount(n);
		if (noun) {
			noun.textContent = label;
			if (label) noun.removeAttribute("hidden");
			else noun.setAttribute("hidden", "");
		}
	}
	var countTimer = 0;
	var countSeq = 0;
	function collectCountBody() {
		syncHiddenIntent();
		var body = new URLSearchParams();
		body.set("action", "pk_find_count");
		body.set("nonce", (typeof pkConfig !== "undefined" && pkConfig.nonce) ? pkConfig.nonce : "");
		body.set("pk_language", (typeof pkConfig !== "undefined" && pkConfig.language) ? pkConfig.language : (document.documentElement.lang || "fr").slice(0, 2));
		["pk_intent", "es_action", "pk_rent", "es_city", "es_type", "es_price_min", "es_price_max", "es_size_min", "es_size_max"].forEach(function (name) {
			var el = root.querySelector("[name='" + name + "']");
			if (!el) return;
			if (el.type === "radio") {
				var checked = root.querySelector("[name='" + name + "']:checked");
				if (checked && checked.value) body.set(name, checked.value);
				return;
			}
			if (el.value) body.set(name, el.value);
		});
		["es_bedrooms_min", "pk_salons_min"].forEach(function (name) {
			var checked = root.querySelector("input[name='" + name + "']:checked");
			if (checked && checked.value && checked.value !== "0") body.set(name, checked.value);
		});
		root.querySelectorAll("input[name='pk_amenity[]']:checked").forEach(function (box) {
			if (box.disabled || (box.closest && box.closest("[hidden]"))) return;
			body.append("pk_amenity[]", box.value);
		});
		var furnished = root.querySelector("input[name='pk_furnished']:checked");
		if (furnished && !furnished.disabled && furnished.value) body.set("pk_furnished", furnished.value);
		return body;
	}
	function refreshCount() {
		if (!window.fetch || typeof pkConfig === "undefined" || !pkConfig.ajaxUrl) return;
		var seq = ++countSeq;
		fetch(pkConfig.ajaxUrl, {
			method: "POST",
			credentials: "same-origin",
			headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
			body: collectCountBody().toString()
		}).then(function (res) {
			return res.json();
		}).then(function (data) {
			if (seq !== countSeq) return;
			if (data && data.success && data.data && typeof data.data.count === "number") paintCount(data.data.count);
		}).catch(function () {});
	}
	function scheduleCount() {
		window.clearTimeout(countTimer);
		countTimer = window.setTimeout(refreshCount, 220);
	}
	root.addEventListener("change", scheduleCount);
	root.addEventListener("click", function (event) {
		var target = event.target;
		if (!target || !target.closest) return;
		if (target.closest(".pk-find-tx-btn, .pk-find-type-card, .pk-suggest, .pk-place-suggestions, .pk-find-chip, .pk-find-room, .pk-find-check")) scheduleCount();
	});
	scheduleCount();
}());

/* Boutons dépôt / annonces : hors du hero, visibles seulement après la barre. */
(function () {
	"use strict";
	var bar = document.querySelector("[data-pk-scroll-actions]");
	var search = document.querySelector(".pk-editorial-search");
	if (!bar || !search) return;
	function sync() {
		if (window.matchMedia("(min-width: 768px)").matches) {
			bar.classList.remove("is-on");
			return;
		}
		var top = 0;
		[document.querySelector(".pk-site-header"), document.querySelector(".pk-main-nav")].forEach(function (el) {
			if (!el) return;
			var box = el.getBoundingClientRect();
			if (box.bottom > top && box.top < 120) top = Math.round(box.bottom);
		});
		top = Math.max(0, top);
		document.documentElement.style.setProperty("--pk-header-h", top + "px");
		var past = search.getBoundingClientRect().bottom < top + 4;
		bar.classList.toggle("is-on", past);
	}
	sync();
	window.addEventListener("scroll", sync, { passive: true });
	window.addEventListener("resize", sync);
}());
