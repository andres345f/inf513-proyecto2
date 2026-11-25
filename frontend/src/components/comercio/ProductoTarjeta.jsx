import { Link } from 'react-router-dom'
import { MapPin, ShoppingCart } from 'lucide-react'
import Insignia from '../ui/Insignia'
import ImagenProducto from './ImagenProducto'
import { formatearMoneda } from '../../utils/formato'
import { cantidadConUnidad, tonoEstado, UNIDADES } from '../../utils/etiquetas'
import './ProductoTarjeta.css'

const DISPONIBILIDAD = { DISPONIBLE: 'Disponible', POR_ENCARGO: 'Por encargo · sin stock ahora', AGOTADO: 'Agotado' }

export default function ProductoTarjeta({ producto, puedeComprar, enviando, alAgregar, consultaCatalogo = '' }) {
  const agotado = producto.disponibilidad === 'AGOTADO'

  return (
    <article className="producto-tarjeta">
      <Link to={`/catalogo/${producto.id}`} state={{ consultaCatalogo }} className="producto-tarjeta__enlace">
        <ImagenProducto producto={producto} />
        <div className="producto-tarjeta__cuerpo">
          <Insignia tono={tonoEstado(producto.disponibilidad)}>{producto.disponibilidad === 'DISPONIBLE' ? `Disponible: ${cantidadConUnidad(producto.stock, producto.unidad_medida)}` : DISPONIBILIDAD[producto.disponibilidad]}</Insignia>
          <h2 className="producto-tarjeta__nombre">{producto.nombre}</h2>
          <p className="producto-tarjeta__presentacion">{producto.presentacion}</p>
          {producto.comunidades?.length > 0 && (
            <p className="producto-tarjeta__origen">
              <MapPin size={14} aria-hidden="true" /> {producto.comunidades.join(', ')}
            </p>
          )}
        </div>
      </Link>
      <div className="producto-tarjeta__pie">
        <p className="producto-tarjeta__precio">
          {formatearMoneda(producto.precio)}
          <span> / {UNIDADES[producto.unidad_medida]?.larga}</span>
        </p>
        {puedeComprar && (
          <button type="button" className="producto-tarjeta__agregar" disabled={agotado || enviando}
            onClick={() => alAgregar(producto)} aria-label={`Agregar ${producto.nombre} al carrito`}>
            <ShoppingCart size={18} aria-hidden="true" />
          </button>
        )}
      </div>
    </article>
  )
}
