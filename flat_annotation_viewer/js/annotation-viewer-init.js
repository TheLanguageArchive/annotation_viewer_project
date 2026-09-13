(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.flatAnnotationViewer = {
    attach: function (context) {
      const elements = once("flatViewer", "#flat-annotation-viewer", context);
      elements.forEach(function (el) {
        el.innerHTML = "";
        el.style.maxWidth = "100%";
        el.style.minWidth = "0";
        el.style.display = "block";
        el.style.overflowX = "hidden";

        const config = drupalSettings.flat_annotation_viewer;
        const viewer = document.createElement("annotation-viewer");
        viewer.setAttribute("url", config.apiUrl);
        viewer.setAttribute("width", "100%");
        viewer.setAttribute("height", "600px");
        viewer.style.display = "block";
        viewer.style.maxWidth = "100%";
        viewer.style.minWidth = "0";
        el.appendChild(viewer);
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
