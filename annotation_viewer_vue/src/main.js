import { defineCustomElement, h, createApp } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import App from './App.vue'

const AnnotationViewerElement = defineCustomElement({
  ...App,
  setup(props) {
    // 1. Create a local Pinia instance FOR THIS Web Component instance
    const pinia = createPinia()
    setActivePinia(pinia)
    
    // 2. We use a small hack to make sure sub-components get this Pinia
    // Vue 3.2.x defineCustomElement does not provide() plugins to children automatically
    const app = createApp(App)
    app.use(pinia)
    
    // Call the original setup
    return App.setup(props)
  }
})

customElements.define('annotation-viewer', AnnotationViewerElement)
