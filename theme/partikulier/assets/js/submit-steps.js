/**
 * Parcours de depot d'annonce en 3 etapes.
 *
 * - autocompletion ville puis quartier (AJAX)
 * - bascules Oui / Non
 * - navigation entre etapes avec validation
 * - apercu redige automatiquement a l'etape 3
 *
 * Sans dependance externe.
 */
(function () {
	"use strict";

	var form = document.getElementById("pk-submit-form");
	if ( ! form || typeof pkConfig === "undefined") {
		return;
	}

	var steps         = Array.prototype.slice.call(form.querySelectorAll(".pk-step"));
	var cityInput     = document.getElementById("pk-city");
	var cityList      = document.getElementById("pk-city-list");
	var cityName      = document.getElementById("pk-city-name");
	var districtWrap  = document.getElementById("pk-district-wrap");
	var districtInput = document.getElementById("pk-district");
	var districtList  = document.getElementById("pk-district-list");
	var districtName  = document.getElementById("pk-district-name");

	/* ---------------------------------------------------------- etapes */

		var stepIndicators = Array.prototype.slice.call(form.querySelectorAll("[data-step-indicator]"));
		var stepperStatus  = document.getElementById("pk-stepper-status");

	function updateStepper(n) {
		stepIndicators.forEach(function (indicator) {
			var step = parseInt(indicator.dataset.stepIndicator, 10);
			indicator.classList.toggle("is-current", step === n);
			indicator.classList.toggle("is-complete", step < n);
			if (step === n) {
				indicator.setAttribute("aria-current", "step");
			} else {
				indicator.removeAttribute("aria-current");
			}
		});
		if (stepperStatus) {
			var current               = form.querySelector('[data-step-indicator="' + n + '"] .pk-stepper-label');
			stepperStatus.textContent = n + " / " + stepIndicators.length + " — " + (current ? current.textContent : "");
		}
	}

	function showStep(n) {
		steps.forEach(function (section) {
			section.hidden = parseInt(section.dataset.step, 10) !== n;
		});
		updateStepper(n);
		var top = form.getBoundingClientRect().top + window.pageYOffset - 24;
		window.scrollTo({ top: top, behavior: "smooth" });
	}

		updateStepper(1);

	function fieldsOf(step) {
		var section = form.querySelector('.pk-step[data-step="' + step + '"]');
		return section ? Array.prototype.slice.call(section.querySelectorAll("input, select, textarea")) : [];
	}

	function validateStep(step) {
		var ok = true;
		fieldsOf(step).forEach(function (field) {
			if (field.type === "hidden" || field.disabled || field.closest("[hidden]")) {
				return;
			}
			field.classList.remove("pk-invalid");
			if (field.id === "pk-price") {
				var raw = (field.value || "").trim();
				field.setCustomValidity(raw && !/^\d[\d\s.,]*$/.test(raw) ? "Le prix ne peut contenir que des chiffres." : "");
			}
			if (!field.checkValidity()) {
				field.classList.add("pk-invalid");
				if (ok) {
					field.focus();
					field.reportValidity();
				}
				ok = false;
			}
		});

		// L'etape 1 exige un lieu : soit choisi dans la liste, soit propose.
		if (step === 1) {
			var proposal      = document.getElementById("pk-proposal");
			var proposedCity  = document.getElementById("pk-proposed-city");
			var usingProposal = proposal && ! proposal.hidden;

			if (usingProposal) {
				if ( ! proposedCity.value.trim()) {
					flagField(proposedCity, (pkConfig.i18n && pkConfig.i18n.chooseCity) || "");
					ok = false;
				}
			} else if ( ! cityName.value) {
				flagField(cityInput, (pkConfig.i18n && pkConfig.i18n.chooseCity) || "");
				ok = false;
			} else if ( ! districtWrap.hidden && ! districtName.value) {
				flagField(districtInput, (pkConfig.i18n && pkConfig.i18n.chooseDistrict) || "");
				ok = false;
			}
		}
		return ok;
	}

	function flagField(field, message) {
		if ( ! field) {
			return;
		}
		field.classList.add("pk-invalid");
		if (message && field.setCustomValidity) {
			field.setCustomValidity(message);
		}
		field.focus();
		if (field.reportValidity) {
			field.reportValidity();
		}
		if (field.setCustomValidity) {
			field.setCustomValidity("");
		}
	}

	/* -------------------------------------- proposition de lieu absent */

	var proposalToggle = document.getElementById("pk-place-missing-toggle");
	var proposalBox    = document.getElementById("pk-proposal");
	if (proposalToggle && proposalBox) {
		proposalToggle.addEventListener("click", function () {
			var opening                = proposalBox.hidden;
			proposalBox.hidden         = ! opening;
			var openL  = proposalToggle.getAttribute("data-pk-label-open") || "";
			var closeL = proposalToggle.getAttribute("data-pk-label-close") || proposalToggle.textContent;
			proposalToggle.textContent = opening ? openL : closeL;

			if (opening) {
				// La saisie libre remplace la selection : on repart propre.
				cityInput.value     = "";
				cityName.value      = "";
				districtName.value  = "";
				districtWrap.hidden = true;
				cityList.hidden     = true;
				document.getElementById("pk-proposed-city").focus();
			} else {
				document.getElementById("pk-proposed-city").value     = "";
				document.getElementById("pk-proposed-district").value = "";
			}
		});
	}

	// Choisir un lieu de la liste annule une proposition en cours.
	function closeProposal() {
		if (proposalBox && ! proposalBox.hidden) {
			proposalBox.hidden                                    = true;
			proposalToggle.textContent = proposalToggle.getAttribute("data-pk-label-close") || proposalToggle.textContent;
			document.getElementById("pk-proposed-city").value     = "";
			document.getElementById("pk-proposed-district").value = "";
		}
	}

	form.addEventListener("click", function (e) {
		var btn = e.target.closest("[data-goto]");
		if ( ! btn) {
			return;
		}
		var target  = parseInt(btn.dataset.goto, 10);
		var current = parseInt(btn.closest(".pk-step").dataset.step, 10);

		if (target > current && ! validateStep(current)) {
			return;
		}
		if (target === 3) {
			buildPreview();
		}
		showStep(target);
	});

	/* ------------------------------------------------------- bascules */

	form.querySelectorAll(".pk-toggle").forEach(function (group) {
		var hidden = form.querySelector('input[name="' + group.dataset.toggle + '"]');
		group.querySelectorAll(".pk-toggle-btn").forEach(function (btn) {
			btn.addEventListener("click", function () {
				group.querySelectorAll(".pk-toggle-btn").forEach(function (b) {
					b.classList.remove("is-on");
				});
				btn.classList.add("is-on");
				if (hidden) {
					hidden.value = btn.dataset.value;
					hidden.dispatchEvent(new Event("change", { bubbles: true }));
				}
			});
		});
	});

	// Superficie de terrasse conditionnelle.
	var terraceHidden = form.querySelector('input[name="pk_terrace"]');
	var terraceField  = document.getElementById("pk-terrace-surface-field");
	if (terraceHidden && terraceField) {
		terraceHidden.addEventListener("change", function () {
			terraceField.hidden = terraceHidden.value !== "Oui";
		});
	}

	// Cartes de choix (role / transaction).
	var agentRefusal = document.getElementById("pk-agent-refusal");

	function refreshAgentRefusal() {
		if ( ! agentRefusal) return false;
		var chosen          = form.querySelector('input[name="pk_role"]:checked');
		var isAgent         = chosen && chosen.value === "agent";
		agentRefusal.hidden = ! isAgent;

		// Le parcours s'arrete la : inutile de laisser croire qu'on peut avancer.
		form.querySelectorAll('.pk-step[data-step="1"] [data-goto]').forEach(function (btn) {
			btn.disabled = ! ! isAgent;
			btn.classList.toggle("pk-btn-disabled", ! ! isAgent);
		});
		return ! ! isAgent;
	}

	form.querySelectorAll(".pk-choice input[type=radio]").forEach(function (radio) {
		radio.addEventListener("change", function () {
			form.querySelectorAll('.pk-choice input[name="' + radio.name + '"]').forEach(function (r) {
				r.closest(".pk-choice").classList.toggle("is-active", r.checked);
			});
			if (radio.name === "pk_transaction") {
				document.getElementById("pk-action-mode").value = radio.value;
			}
			if (radio.name === "pk_role" && refreshAgentRefusal()) {
				agentRefusal.scrollIntoView({ behavior: "smooth", block: "center" });
			}
		});
	});

	refreshAgentRefusal();

	/* ------------------------------------------------ autocompletion */

	function fetchPlaces(params) {
		var url = pkConfig.ajaxUrl +
			"?action=pk_places_search&nonce=" + encodeURIComponent(pkConfig.placesNonce) +
			"&q=" + encodeURIComponent(params.q || "") +
			"&scope=" + encodeURIComponent(params.scope || "city") +
			"&city=" + encodeURIComponent(params.city || "");

		return fetch(url, { credentials: "same-origin" })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				return (data && data.success && data.data && data.data.results) ? data.data.results : [];
			})
			.catch(function () { return []; });
	}

	function renderList(listEl, results, onPick) {
		listEl.innerHTML = "";
		if ( ! results.length) {
			listEl.hidden = true;
			return;
		}
		results.forEach(function (item) {
			var li       = document.createElement("li");
			li.className = "pk-suggest-item";
			li.setAttribute("role", "option");
			li.tabIndex = 0;

			var label         = document.createElement("span");
			label.className   = "pk-suggest-label";
			label.textContent = item.label;

			var meta         = document.createElement("span");
			meta.className   = "pk-suggest-meta";
			meta.textContent = item.meta || "";

			li.appendChild(label);
			li.appendChild(meta);

			function pick() {
				onPick(item);
				listEl.hidden = true;
			}
			li.addEventListener("mousedown", function (e) { e.preventDefault(); pick(); });
			li.addEventListener("keydown", function (e) {
				if (e.key === "Enter" || e.key === " ") { e.preventDefault(); pick(); }
			});
			listEl.appendChild(li);
		});
		listEl.hidden = false;
	}

	function debounce(fn, wait) {
		var t;
		return function () {
			var args = arguments;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(null, args); }, wait);
		};
	}

	function openDistricts(city) {
		districtWrap.hidden       = false;
		districtInput.placeholder = "Choisissez un quartier de " + city;
		districtInput.value       = "";
		districtName.value        = "";
		fetchPlaces({ scope: "district", city: city }).then(function (results) {
			renderList(districtList, results, function (item) {
				districtInput.value = item.district;
				districtName.value  = item.district;
				districtInput.classList.remove("pk-invalid");
			});
		});
	}

	if (cityInput) {
		cityInput.addEventListener("input", debounce(function () {
			cityName.value      = "";
			districtName.value  = "";
			districtWrap.hidden = true;
			var q               = cityInput.value.trim();
			if ( ! q) { cityList.hidden = true; return; }

			fetchPlaces({ q: q, scope: "city" }).then(function (results) {
				renderList(cityList, results, function (item) {
					// Quartier choisi directement : ville + quartier remplis d'un coup.
					if (item.district) {
						cityInput.value     = item.district + ", " + item.city;
						cityName.value      = item.city;
						districtName.value  = item.district;
						districtWrap.hidden = true;
					} else {
						cityInput.value = item.city;
						cityName.value  = item.city;
						openDistricts(item.city);
					}
					closeProposal();
					cityInput.classList.remove("pk-invalid");
				});
			});
		}, 160));

		cityInput.addEventListener("focus", function () {
			if (cityInput.value.trim()) { cityInput.dispatchEvent(new Event("input")); }
		});
		cityInput.addEventListener("blur", function () {
			setTimeout(function () { cityList.hidden = true; }, 150);
		});
	}

	if (districtInput) {
		districtInput.addEventListener("input", debounce(function () {
			districtName.value = "";
			fetchPlaces({ scope: "district", city: cityName.value, q: districtInput.value.trim() })
				.then(function (results) {
					renderList(districtList, results, function (item) {
						districtInput.value = item.district;
						districtName.value  = item.district;
						districtInput.classList.remove("pk-invalid");
					});
				});
		}, 140));
		districtInput.addEventListener("focus", function () {
			districtInput.dispatchEvent(new Event("input"));
		});
		districtInput.addEventListener("blur", function () {
			setTimeout(function () { districtList.hidden = true; }, 150);
		});
	}

	/* ---------------------------------------------------------- photos */

	var photoInput   = document.getElementById("pk-photos");
	var photoPreview = document.getElementById("pk-photo-preview");
	var dropzone     = document.getElementById("pk-dropzone");
	var MAX_PHOTOS   = 15;

	if (photoInput && photoPreview) {
		// Liste en mémoire, comme main.js : chaque sélection s'ajoute.
		var chosen    = [];
		var syncing   = false;
		var squeezeQ  = Promise.resolve();
		var dropLabel = dropzone ? dropzone.querySelector("strong") : null;

		function liveCount() {
			return chosen.filter(Boolean).length;
		}

		function updateDropLabel() {
			if ( ! dropLabel) return;
			var n = liveCount();
			dropLabel.textContent = n
				? (n + " photo" + (n > 1 ? "s" : "") + " sélectionnée" + (n > 1 ? "s" : ""))
				: "Ajoutez vos photos";
		}

		function syncInput() {
			try {
				var dt = new DataTransfer();
				chosen.forEach(function (f) { if (f) dt.items.add(f); });
				syncing = true;
				photoInput.files = dt.files;
			} catch (e) {
				// Navigateur trop ancien : on laisse la selection native.
			}
			syncing = false;
		}

		/**
		 * Allège la photo dans le navigateur. L’annonceur ne fait rien :
		 * en cas d’échec on garde le fichier d’origine (le serveur recadre).
		 */
		function squeezePhoto(file) {
			return new Promise(function (resolve) {
				var keep = function () { resolve(file); };
				if ( ! file || typeof HTMLCanvasElement === "undefined" || typeof File === "undefined") {
					keep();
					return;
				}
				var mime = (file.type || "").toLowerCase();
				var jpegName = /\.jpe?g$/i.test(file.name || "");
				if (mime ? (mime !== "image/jpeg" && mime !== "image/jpg") : ! jpegName) {
					keep();
					return;
				}
				var maxEdge = 1920;
				var quality = 0.82;
				var maxBytes = 409600;

				function draw(src, w, h, closeSrc) {
					if ( ! w || ! h) {
						if (closeSrc) closeSrc();
						keep();
						return;
					}
					var scale = Math.min(1, maxEdge / Math.max(w, h));
					if (scale === 1 && file.size <= maxBytes && file.type === "image/jpeg") {
						if (closeSrc) closeSrc();
						keep();
						return;
					}
					var cw = Math.max(1, Math.round(w * scale));
					var ch = Math.max(1, Math.round(h * scale));
					var canvas = document.createElement("canvas");
					canvas.width  = cw;
					canvas.height = ch;
					var ctx = canvas.getContext("2d", { alpha: false });
					if ( ! ctx || typeof canvas.toBlob !== "function") {
						if (closeSrc) closeSrc();
						keep();
						return;
					}
					ctx.drawImage(src, 0, 0, cw, ch);
					if (closeSrc) closeSrc();
					canvas.toBlob(function (blob) {
						if ( ! blob || blob.size < 32) {
							keep();
							return;
						}
						if (scale === 1 && blob.size >= file.size) {
							keep();
							return;
						}
						var base = (file.name || "photo").replace(/\.[^.]+$/, "") || "photo";
						resolve(new File([blob], base + ".jpg", { type: "image/jpeg", lastModified: Date.now() }));
					}, "image/jpeg", quality);
				}

				if (window.createImageBitmap) {
					var tryBitmap = function (opts) {
						return window.createImageBitmap(file, opts || {});
					};
					tryBitmap({ imageOrientation: "from-image" }).catch(function () {
						return tryBitmap();
					}).then(function (bitmap) {
						if ( ! bitmap) { keep(); return; }
						draw(bitmap, bitmap.width, bitmap.height, function () {
							if (bitmap.close) bitmap.close();
						});
					}).catch(keep);
					return;
				}

				var img = new Image();
				var url = URL.createObjectURL(file);
				img.onload = function () {
					URL.revokeObjectURL(url);
					draw(img, img.naturalWidth || img.width, img.naturalHeight || img.height, null);
				};
				img.onerror = function () {
					URL.revokeObjectURL(url);
					keep();
				};
				img.src = url;
			});
		}

		function addFiles(files) {
			files = Array.prototype.slice.call(files || []).filter(function (f) {
				return ! f.type || f.type.indexOf("image/") === 0 || /\.(jpe?g|png|webp|avif|heic|heif)$/i.test(f.name);
			});
			var total = liveCount() + files.length;
			files     = files.slice(0, Math.max(0, MAX_PHOTOS - liveCount()));
			files.forEach(function (f) {
				chosen.push(f);
				var li  = document.createElement("li");
				var img = document.createElement("img");
				img.src     = URL.createObjectURL(f);
				img.alt     = f.name;
				img.loading = "lazy";
				li.appendChild(img);
				li.title        = "Retirer " + f.name;
				li.style.cursor = "pointer";
				var idx = chosen.length - 1;
				li.addEventListener("click", function () {
					chosen[idx] = null;
					li.remove();
					syncInput();
					updateDropLabel();
				});
				photoPreview.appendChild(li);
				squeezeQ = squeezeQ.then(function () {
					return squeezePhoto(f).then(function (out) {
						if (chosen[idx] === f && out) {
							chosen[idx] = out;
							syncInput();
						}
					});
				}).catch(function () { /* photo d'origine conservée */ });
			});
			if (total > MAX_PHOTOS) {
				setStatus("15 photos maximum. Les fichiers en trop ont été ignorés.");
			}
			syncInput();
			updateDropLabel();
		}

		photoInput.addEventListener("change", function () {
			if (syncing) return;
			var incoming = Array.prototype.slice.call(photoInput.files || []);
			photoInput.value = "";
			addFiles(incoming);
		});

		if (dropzone) {
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
				if (e.dataTransfer && e.dataTransfer.files) {
					addFiles(e.dataTransfer.files);
				}
			});
		}
	}

	function setStatus(message) {
		var el = document.getElementById("pk-form-status");
		if (el) { el.textContent = message; }
	}

	/* --------------------------------------------------------- apercu */

	function buildPreview() {
		var fd = new FormData(form);
		fd.set("action", "pk_listing_preview");
		fd.set("nonce", pkConfig.submitNonce);
		fd.delete("pk_photos[]");

		var typeSelect = document.getElementById("pk-type");
		if (typeSelect && typeSelect.selectedIndex >= 0) {
			fd.set("pk_type_label", typeSelect.options[typeSelect.selectedIndex].text);
		}

		fetch(pkConfig.ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if ( ! data || ! data.success) { return; }
				var p = data.data;

				document.getElementById("pk-preview-kicker").textContent = p.kicker || "";
				document.getElementById("pk-preview-title").textContent  = p.title || "";
				document.getElementById("pk-preview-desc").textContent   = p.description || "";
				document.getElementById("pk-preview-price").textContent  = p.price || "";

				var facts       = document.getElementById("pk-preview-facts");
				facts.innerHTML = "";
				(p.facts || []).forEach(function (fact) {
					var li         = document.createElement("li");
					li.textContent = fact;
					facts.appendChild(li);
				});

				var titleField = document.getElementById("pk-title");
				if (titleField && ! titleField.dataset.touched) {
					titleField.value = p.title || "";
				}
				document.getElementById("pk-description").value = p.description || "";

				// Vignette : premiere photo choisie.
				var media       = document.getElementById("pk-preview-media");
				media.innerHTML = "";
				if (photoInput && photoInput.files && photoInput.files[0]) {
					var img = document.createElement("img");
					img.src = URL.createObjectURL(photoInput.files[0]);
					img.alt = "";
					media.appendChild(img);
				} else {
					media.classList.add("is-empty");
				}
			});
	}

	var titleField = document.getElementById("pk-title");
	if (titleField) {
		titleField.addEventListener("input", function () { titleField.dataset.touched = "1"; });
	}

	// Le mot personnel est ajoute a la description generee.
	var extra = document.getElementById("pk-extra");
	if (extra) {
		extra.addEventListener("input", function () {
			var base = document.getElementById("pk-description");
			if ( ! base.dataset.base) { base.dataset.base = base.value; }
			base.value = extra.value.trim() ? base.dataset.base + "\n\n" + extra.value.trim() : base.dataset.base;
		});
	}
}());
