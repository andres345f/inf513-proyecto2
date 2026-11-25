import { useState } from 'react'
import { Package } from 'lucide-react'
import { rutaPublica } from '../../utils/rutas'
import './ImagenProducto.css'

/** Imagen del producto o, si no tiene o no carga, un marcador con su categoría. */
export default function ImagenProducto({ producto, grande = false }) {
  const [fallo, setFallo] = useState(null)
  const clase = `imagen-producto${grande ? ' imagen-producto--grande' : ''}`
  const imagen = rutaPublica(producto.imagen_url)
  const usarImagen = imagen && fallo !== imagen

  if (usarImagen) {
    return (
      <img src={imagen} alt={producto.nombre} className={clase} loading="lazy"
        onError={() => setFallo(imagen)} />
    )
  }

  return (
    <div className={`${clase} imagen-producto--vacia`} role="img" aria-label={`Sin foto de ${producto.nombre}`}>
      <Package size={grande ? 56 : 36} aria-hidden="true" />
      <span>Foto no disponible</span>
    </div>
  )
}
