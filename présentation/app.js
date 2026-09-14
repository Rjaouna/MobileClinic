(function () {
    "use strict";

    const STORAGE_KEY = "symclinic-validation-fonctionnelle-v1";
    const STATUS_OPTIONS = [
        { value: "proposed", label: "À valider" },
        { value: "approved", label: "Validé" },
        { value: "change", label: "À modifier" },
        { value: "excluded", label: "Non retenu" }
    ];

    const elements = {
        modules: document.getElementById("modules"),
        navigation: document.getElementById("module-navigation"),
        editorDialog: document.getElementById("editor-dialog"),
        editorForm: document.getElementById("editor-form"),
        editorMode: document.getElementById("editor-mode"),
        editorFeatureId: document.getElementById("editor-feature-id"),
        editorModule: document.getElementById("editor-module"),
        editorAudience: document.getElementById("editor-audience"),
        editorFeatureTitle: document.getElementById("editor-feature-title"),
        editorFeatureDescription: document.getElementById("editor-feature-description"),
        editorStatus: document.getElementById("editor-status"),
        editorModuleEyebrow: document.getElementById("editor-module-eyebrow"),
        editorModuleTitle: document.getElementById("editor-module-title"),
        editorModuleDescription: document.getElementById("editor-module-description"),
        featureFields: document.getElementById("feature-fields"),
        moduleFields: document.getElementById("module-fields"),
        dialogEyebrow: document.getElementById("dialog-eyebrow"),
        dialogTitle: document.getElementById("dialog-title"),
        dialogDescription: document.getElementById("dialog-description"),
        importInput: document.getElementById("import-input"),
        toast: document.getElementById("toast"),
        saveLabel: document.getElementById("save-label"),
        countProposed: document.getElementById("count-proposed"),
        countApproved: document.getElementById("count-approved"),
        countChange: document.getElementById("count-change"),
        countExcluded: document.getElementById("count-excluded"),
        mobileMenuButton: document.getElementById("mobile-menu-button")
    };

    let state = null;
    let toastTimer = null;

    function initialData() {
        return JSON.parse(document.getElementById("initial-data").textContent);
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function normalizeState(value) {
        if (!value || !Array.isArray(value.modules)) {
            throw new Error("Le fichier JSON ne contient pas de liste de modules.");
        }

        value.modules.forEach(function (module, moduleIndex) {
            module.id = module.id || "module-" + (moduleIndex + 1);
            module.eyebrow = module.eyebrow || "Module";
            module.title = module.title || "Module sans titre";
            module.description = module.description || "";
            module.clientHeading = module.clientHeading || "Fonctionnalités côté client";
            module.adminHeading = module.adminHeading || "Fonctionnalités côté administration";
            module.client = Array.isArray(module.client) ? module.client : [];
            module.admin = Array.isArray(module.admin) ? module.admin : [];

            ["client", "admin"].forEach(function (audience) {
                module[audience].forEach(function (feature, featureIndex) {
                    feature.id = feature.id || createId(module.id + "-" + audience + "-" + featureIndex);
                    feature.title = feature.title || "Fonctionnalité sans titre";
                    feature.description = feature.description || "";
                    if (!STATUS_OPTIONS.some(function (option) { return option.value === feature.status; })) {
                        feature.status = "proposed";
                    }
                });
            });
        });

        return value;
    }

    async function loadState() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            try {
                return normalizeState(JSON.parse(saved));
            } catch (error) {
                localStorage.removeItem(STORAGE_KEY);
            }
        }

        if (window.location.protocol !== "file:") {
            try {
                const response = await fetch("fonctionnalites.json", { cache: "no-store" });
                if (response.ok) {
                    return normalizeState(await response.json());
                }
            } catch (error) {
                // The embedded copy keeps the presentation usable without a server.
            }
        }

        return normalizeState(initialData());
    }

    function saveState(message) {
        state.updatedAt = new Date().toISOString();
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        elements.saveLabel.textContent = "Modifications sauvegardées";
        updateSummary();
        if (message) {
            showToast(message);
        }
    }

    function createElement(tag, className, text) {
        const element = document.createElement(tag);
        if (className) {
            element.className = className;
        }
        if (typeof text === "string") {
            element.textContent = text;
        }
        return element;
    }

    function createId(prefix) {
        return prefix + "-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 7);
    }

    function render() {
        renderNavigation();
        renderModules();
        updateModuleOptions();
        updateSummary();
    }

    function renderNavigation() {
        elements.navigation.replaceChildren();

        state.modules.forEach(function (module, index) {
            const link = createElement("a");
            link.href = "#module-" + module.id;
            link.append(createElement("span", "", String(index + 1).padStart(2, "0")));
            link.append(document.createTextNode(module.eyebrow));
            elements.navigation.append(link);
        });
    }

    function renderModules() {
        elements.modules.replaceChildren();

        state.modules.forEach(function (module, index) {
            const section = createElement("section", "module-section");
            section.id = "module-" + module.id;
            section.dataset.moduleId = module.id;

            const heading = createElement("header", "module-heading");
            heading.append(createElement("span", "module-number", String(index + 1).padStart(2, "0")));

            const headingCopy = createElement("div");
            headingCopy.append(createElement("p", "eyebrow", module.eyebrow));
            headingCopy.append(createElement(index === 0 ? "h1" : "h2", "", module.title));
            headingCopy.append(createElement("p", "module-heading-description", module.description));
            heading.append(headingCopy);

            const editModuleButton = createElement("button", "button button-secondary button-small", "Modifier le module");
            editModuleButton.type = "button";
            editModuleButton.dataset.action = "edit-module";
            editModuleButton.dataset.moduleId = module.id;
            heading.append(editModuleButton);
            section.append(heading);

            const audienceGrid = createElement("div", "audience-grid");
            audienceGrid.append(createAudience(module, "client", module.clientHeading));
            audienceGrid.append(createAudience(module, "admin", module.adminHeading));
            section.append(audienceGrid);
            elements.modules.append(section);
        });
    }

    function createAudience(module, audience, headingText) {
        const article = createElement("article", "audience audience-" + audience);
        const header = createElement("header", "audience-header");
        header.append(createElement("span", "audience-label", audience === "client" ? "Client" : "Administration"));
        header.append(createElement("h3", "", headingText));
        article.append(header);

        const list = createElement("ul", "feature-list");
        module[audience].forEach(function (feature) {
            list.append(createFeatureItem(module, audience, feature));
        });

        if (module[audience].length === 0) {
            const empty = createElement("li", "feature-item feature-empty");
            empty.append(createElement("p", "", "Aucune fonctionnalité dans cette partie pour le moment."));
            list.append(empty);
        }

        article.append(list);

        const footer = createElement("footer", "audience-footer");
        footer.append(createElement("small", "", module[audience].length + " fonctionnalité" + (module[audience].length > 1 ? "s" : "")));

        const footerActions = createElement("div", "audience-footer-actions");
        const clearButton = createElement("button", "button button-danger button-small", "Tout supprimer");
        clearButton.type = "button";
        clearButton.dataset.action = "clear-features";
        clearButton.dataset.moduleId = module.id;
        clearButton.dataset.audience = audience;
        clearButton.disabled = module[audience].length === 0;
        footerActions.append(clearButton);

        const addButton = createElement("button", "button button-primary button-small", "Ajouter une fonctionnalité");
        addButton.type = "button";
        addButton.dataset.action = "add-feature";
        addButton.dataset.moduleId = module.id;
        addButton.dataset.audience = audience;
        footerActions.append(addButton);
        footer.append(footerActions);
        article.append(footer);

        return article;
    }

    function createFeatureItem(module, audience, feature) {
        const item = createElement("li", "feature-item");
        item.dataset.status = feature.status;
        item.dataset.featureId = feature.id;

        const copyElement = createElement("div", "feature-copy");
        copyElement.append(createElement("strong", "", feature.title));
        copyElement.append(createElement("p", "", feature.description));
        item.append(copyElement);

        const controls = createElement("div", "feature-controls");
        const statusSelect = createElement("select", "status-select status-" + feature.status);
        statusSelect.setAttribute("aria-label", "Décision pour " + feature.title);
        statusSelect.dataset.action = "change-status";
        statusSelect.dataset.moduleId = module.id;
        statusSelect.dataset.audience = audience;
        statusSelect.dataset.featureId = feature.id;
        STATUS_OPTIONS.forEach(function (optionData) {
            const option = createElement("option", "", optionData.label);
            option.value = optionData.value;
            option.selected = optionData.value === feature.status;
            statusSelect.append(option);
        });
        controls.append(statusSelect);

        const editButton = createElement("button", "button button-secondary button-small", "Modifier");
        editButton.type = "button";
        editButton.dataset.action = "edit-feature";
        editButton.dataset.moduleId = module.id;
        editButton.dataset.audience = audience;
        editButton.dataset.featureId = feature.id;
        controls.append(editButton);

        const deleteButton = createElement("button", "button button-danger button-small", "Supprimer");
        deleteButton.type = "button";
        deleteButton.dataset.action = "delete-feature";
        deleteButton.dataset.moduleId = module.id;
        deleteButton.dataset.audience = audience;
        deleteButton.dataset.featureId = feature.id;
        controls.append(deleteButton);

        item.append(controls);
        return item;
    }

    function updateModuleOptions() {
        const selectedValue = elements.editorModule.value;
        elements.editorModule.replaceChildren();
        state.modules.forEach(function (module) {
            const option = createElement("option", "", module.eyebrow + " - " + module.title);
            option.value = module.id;
            elements.editorModule.append(option);
        });
        if (state.modules.some(function (module) { return module.id === selectedValue; })) {
            elements.editorModule.value = selectedValue;
        }
    }

    function updateSummary() {
        const counts = { proposed: 0, approved: 0, change: 0, excluded: 0 };
        state.modules.forEach(function (module) {
            module.client.concat(module.admin).forEach(function (feature) {
                counts[feature.status] += 1;
            });
        });
        elements.countProposed.textContent = counts.proposed;
        elements.countApproved.textContent = counts.approved;
        elements.countChange.textContent = counts.change;
        elements.countExcluded.textContent = counts.excluded;
    }

    function setEditorMode(mode) {
        const isFeature = mode === "feature";
        elements.editorMode.value = mode;
        elements.featureFields.hidden = !isFeature;
        elements.moduleFields.hidden = isFeature;

        elements.featureFields.querySelectorAll("input, select, textarea").forEach(function (field) {
            field.disabled = !isFeature;
        });
        elements.moduleFields.querySelectorAll("input, select, textarea").forEach(function (field) {
            field.disabled = isFeature;
        });
    }

    function openAddFeature(moduleId, audience) {
        setEditorMode("feature");
        elements.editorForm.reset();
        elements.editorFeatureId.value = "";
        updateModuleOptions();
        elements.editorModule.value = moduleId || state.modules[0].id;
        elements.editorAudience.value = audience || "client";
        elements.editorStatus.value = "proposed";
        elements.dialogEyebrow.textContent = "Nouvelle proposition";
        elements.dialogTitle.textContent = "Ajouter une fonctionnalité";
        elements.dialogDescription.textContent = "Ajoutez un nouveau point demandé pendant l'échange avec le client.";
        showDialog();
        elements.editorFeatureTitle.focus();
    }

    function openEditFeature(moduleId, audience, featureId) {
        const result = findFeature(moduleId, audience, featureId);
        if (!result) {
            return;
        }

        setEditorMode("feature");
        elements.editorFeatureId.value = featureId;
        updateModuleOptions();
        elements.editorModule.value = moduleId;
        elements.editorAudience.value = audience;
        elements.editorFeatureTitle.value = result.feature.title;
        elements.editorFeatureDescription.value = result.feature.description;
        elements.editorStatus.value = result.feature.status;
        elements.dialogEyebrow.textContent = "Point à valider";
        elements.dialogTitle.textContent = "Modifier la fonctionnalité";
        elements.dialogDescription.textContent = "Reformulez ce point pour qu'il corresponde exactement à la décision prise avec le client.";
        showDialog();
        elements.editorFeatureTitle.focus();
    }

    function openEditModule(moduleId) {
        const module = state.modules.find(function (item) { return item.id === moduleId; });
        if (!module) {
            return;
        }

        setEditorMode("module");
        elements.editorModule.value = moduleId;
        elements.editorModuleEyebrow.value = module.eyebrow;
        elements.editorModuleTitle.value = module.title;
        elements.editorModuleDescription.value = module.description;
        elements.dialogEyebrow.textContent = "Présentation du module";
        elements.dialogTitle.textContent = "Modifier le module";
        elements.dialogDescription.textContent = "Adaptez le nom et la présentation générale du module pendant la réunion.";
        showDialog();
        elements.editorModuleTitle.focus();
    }

    function showDialog() {
        document.body.classList.add("dialog-open");
        elements.editorDialog.showModal();
    }

    function closeDialog() {
        elements.editorDialog.close();
        document.body.classList.remove("dialog-open");
    }

    function findFeature(moduleId, audience, featureId) {
        const module = state.modules.find(function (item) { return item.id === moduleId; });
        if (!module || !Array.isArray(module[audience])) {
            return null;
        }
        const feature = module[audience].find(function (item) { return item.id === featureId; });
        return feature ? { module: module, feature: feature } : null;
    }

    function submitEditor(event) {
        event.preventDefault();

        if (elements.editorMode.value === "module") {
            const module = state.modules.find(function (item) { return item.id === elements.editorModule.value; });
            if (module) {
                module.eyebrow = elements.editorModuleEyebrow.value.trim();
                module.title = elements.editorModuleTitle.value.trim();
                module.description = elements.editorModuleDescription.value.trim();
                saveState("Le module a été mis à jour.");
            }
            closeDialog();
            render();
            return;
        }

        const targetModuleId = elements.editorModule.value;
        const targetAudience = elements.editorAudience.value;
        const featureId = elements.editorFeatureId.value;
        const payload = {
            title: elements.editorFeatureTitle.value.trim(),
            description: elements.editorFeatureDescription.value.trim(),
            status: elements.editorStatus.value
        };

        if (featureId) {
            let source = null;
            state.modules.forEach(function (module) {
                ["client", "admin"].forEach(function (audience) {
                    const index = module[audience].findIndex(function (feature) { return feature.id === featureId; });
                    if (index !== -1) {
                        source = { module: module, audience: audience, index: index, feature: module[audience][index] };
                    }
                });
            });

            if (source) {
                source.module[source.audience].splice(source.index, 1);
                const targetModule = state.modules.find(function (module) { return module.id === targetModuleId; });
                targetModule[targetAudience].push(Object.assign(source.feature, payload));
            }
            saveState("La fonctionnalité a été mise à jour.");
        } else {
            const targetModule = state.modules.find(function (module) { return module.id === targetModuleId; });
            targetModule[targetAudience].push({
                id: createId(targetModuleId + "-" + targetAudience),
                title: payload.title,
                description: payload.description,
                status: payload.status
            });
            saveState("La fonctionnalité a été ajoutée.");
        }

        closeDialog();
        render();
        window.location.hash = "module-" + targetModuleId;
    }

    function deleteFeature(moduleId, audience, featureId) {
        const result = findFeature(moduleId, audience, featureId);
        if (!result) {
            return;
        }

        if (!window.confirm("Supprimer la fonctionnalité « " + result.feature.title + " » ?")) {
            return;
        }

        result.module[audience] = result.module[audience].filter(function (feature) {
            return feature.id !== featureId;
        });
        saveState("La fonctionnalité a été supprimée.");
        render();
    }

    function clearFeatures(moduleId, audience) {
        const module = state.modules.find(function (item) { return item.id === moduleId; });
        if (!module || !Array.isArray(module[audience]) || module[audience].length === 0) {
            return;
        }

        const blockName = audience === "client" ? "Client" : "Administration";
        if (!window.confirm("Supprimer toutes les fonctionnalités du bloc « " + blockName + " » ?")) {
            return;
        }

        module[audience] = [];
        saveState("Toutes les fonctionnalités du bloc ont été supprimées.");
        render();
    }

    function changeStatus(select) {
        const result = findFeature(select.dataset.moduleId, select.dataset.audience, select.dataset.featureId);
        if (!result) {
            return;
        }

        result.feature.status = select.value;
        saveState("La décision a été enregistrée.");
        const item = select.closest(".feature-item");
        item.dataset.status = select.value;
        select.className = "status-select status-" + select.value;
    }

    function exportJson() {
        state.exportedAt = new Date().toISOString();
        const blob = new Blob([JSON.stringify(state, null, 2)], { type: "application/json" });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        const date = new Date().toISOString().slice(0, 10);
        link.href = url;
        link.download = "symclinic-validation-" + date + ".json";
        document.body.append(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
        showToast("Le fichier JSON de validation a été exporté.");
    }

    async function importJson(file) {
        if (!file) {
            return;
        }

        try {
            const imported = normalizeState(JSON.parse(await file.text()));
            state = imported;
            saveState("Le fichier JSON a été importé.");
            render();
            window.location.hash = "module-" + state.modules[0].id;
        } catch (error) {
            showToast("Import impossible : " + error.message);
        } finally {
            elements.importInput.value = "";
        }
    }

    function showToast(message) {
        window.clearTimeout(toastTimer);
        elements.toast.textContent = message;
        elements.toast.classList.add("is-visible");
        toastTimer = window.setTimeout(function () {
            elements.toast.classList.remove("is-visible");
        }, 2800);
    }

    elements.modules.addEventListener("click", function (event) {
        const button = event.target.closest("button[data-action]");
        if (!button) {
            return;
        }

        if (button.dataset.action === "add-feature") {
            openAddFeature(button.dataset.moduleId, button.dataset.audience);
        } else if (button.dataset.action === "edit-feature") {
            openEditFeature(button.dataset.moduleId, button.dataset.audience, button.dataset.featureId);
        } else if (button.dataset.action === "delete-feature") {
            deleteFeature(button.dataset.moduleId, button.dataset.audience, button.dataset.featureId);
        } else if (button.dataset.action === "clear-features") {
            clearFeatures(button.dataset.moduleId, button.dataset.audience);
        } else if (button.dataset.action === "edit-module") {
            openEditModule(button.dataset.moduleId);
        }
    });

    elements.modules.addEventListener("change", function (event) {
        if (event.target.matches("select[data-action='change-status']")) {
            changeStatus(event.target);
        }
    });

    document.getElementById("add-feature-button").addEventListener("click", function () { openAddFeature(); });
    document.getElementById("mobile-add-feature-button").addEventListener("click", function () { openAddFeature(); });
    document.getElementById("export-button").addEventListener("click", exportJson);
    document.getElementById("mobile-export-button").addEventListener("click", exportJson);
    document.getElementById("import-button").addEventListener("click", function () { elements.importInput.click(); });
    elements.importInput.addEventListener("change", function () { importJson(elements.importInput.files[0]); });
    elements.editorForm.addEventListener("submit", submitEditor);
    document.getElementById("close-dialog-button").addEventListener("click", closeDialog);
    document.getElementById("cancel-dialog-button").addEventListener("click", closeDialog);
    elements.editorDialog.addEventListener("close", function () { document.body.classList.remove("dialog-open"); });

    elements.mobileMenuButton.addEventListener("click", function () {
        const isOpen = elements.navigation.classList.toggle("is-open");
        elements.mobileMenuButton.setAttribute("aria-expanded", String(isOpen));
    });

    elements.navigation.addEventListener("click", function (event) {
        if (event.target.closest("a")) {
            elements.navigation.classList.remove("is-open");
            elements.mobileMenuButton.setAttribute("aria-expanded", "false");
        }
    });

    loadState().then(function (loadedState) {
        state = clone(loadedState);
        render();
    }).catch(function () {
        state = normalizeState(initialData());
        render();
        showToast("Les données initiales ont été chargées.");
    });
})();
