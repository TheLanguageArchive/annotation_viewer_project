import { defineCustomElement, h, createApp } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import App from './App.vue'

const FlatMediaPlayerElement = defineCustomElement({
  ...App,
  setup(props) {
    // Isolated Pinia instance for each custom element instance
    const pinia = createPinia()
    setActivePinia(pinia)

    // We mount a dummy app to initialize Pinia in the subtree
    // This is the pattern used in annotation_viewer_vue
    const app = createApp(App)
    app.use(pinia)

    return App.setup(props)
  }
})

if (!customElements.get('flat-media-player')) {
  customElements.define('flat-media-player', FlatMediaPlayerElement)
}
