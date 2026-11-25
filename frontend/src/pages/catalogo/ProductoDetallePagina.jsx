import { useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { ArrowLeft, Factory, MapPin, Sprout } from 'lucide-react'
import { comercioApi } from '../../api/comercioApi'
import ComprarComoCliente from '../../components/comercio/ComprarComoCliente'
import ImagenProducto from '../../components/comercio/ImagenProducto'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import Cargando from '../../components/ui/Cargando'
import Insignia from '../../components/ui/Insignia'
import { useAgregarAlCarrito } from '../../hooks/useAgregarAlCarrito'
import { useCargaApi } from '../../hooks/useCargaApi'
import { cantidadConUnidad, tonoEstado, UNIDADES } from '../../utils/etiquetas'
import { formatearFecha, formatearMoneda } from '../../utils/formato'
import PaginaEstado from '../estado/PaginaEstado'
import './ProductoDetallePagina.css'

export default function ProductoDetallePagina() {
  const { id } = useParams()
  const ubicacion = useLocation()
  const volver = { pathname: '/catalogo', search: ubicacion.state?.consultaCatalogo ?? '' }
  const { datos: producto, error, cargando } = useCargaApi(() => comercioApi.producto(id), [id])
  const { agregar, estado, puedeComprar } = useAgregarAlCarrito()
  const [cantidad, setCantidad] = useState('1')
  const [errorCantidad, setErrorCantidad] = useState(null)

  if (cargando) return <Cargando texto="Cargando producto…" />
  if (error || !producto) return (
    <PaginaEstado icono={Sprout} titulo="Producto no disponible" sinContador>
      <p>{error || 'El producto no está a la venta.'}</p>
      <Link to={volver}>Volver al catálogo</Link>
    </PaginaEstado>
  )

  const porUnidad = producto.unidad_medida === 'UNIDAD'

  const alAgregar = () => {
    const numero = Number(cantidad)
    if (!cantidad || Number.isNaN(numero) || numero <= 0) return setErrorCantidad('Ingresa una cantidad mayor que cero.')
    if (porUnidad && !Number.isInteger(numero)) return setErrorCantidad('Este producto se vende por unidades enteras.')
    if (numero > 999) return setErrorCantidad('Puedes llevar como máximo 999.')
    setErrorCantidad(null)
    agregar(producto, numero)
  }

  return (
    <div className="producto-detalle">
      <Link to={volver} className="producto-detalle__volver"><ArrowLeft size={16} aria-hidden="true" /> Volver al catálogo</Link>

      <div className="producto-detalle__principal">
        <ImagenProducto producto={producto} grande />

        <section className="producto-detalle__info">
          <p className="producto-detalle__categoria">{producto.categoria}</p>
          <h1>{producto.nombre}</h1>
          <p className="producto-detalle__presentacion">{producto.presentacion}</p>
          <p className="producto-detalle__precio">
            {formatearMoneda(producto.precio)} <span>por {UNIDADES[producto.unidad_medida]?.larga}</span>
          </p>
          <p className="producto-detalle__stock">
            <Insignia tono={tonoEstado(producto.disponibilidad)}>
              {producto.disponibilidad === 'DISPONIBLE' ? `Disponible: ${cantidadConUnidad(producto.stock, producto.unidad_medida)}`
                : producto.disponibilidad === 'POR_ENCARGO' ? 'Sin stock · se puede encargar' : 'Agotado'}
            </Insignia>
          </p>
          {producto.descripcion && <p>{producto.descripcion}</p>}

          {puedeComprar && producto.disponibilidad !== 'AGOTADO' && (
            <div className="producto-detalle__compra">
              <div className="producto-detalle__cantidad">
                <label htmlFor="cantidad">Cantidad ({UNIDADES[producto.unidad_medida]?.corta})</label>
                <input id="cantidad" type="number" min={porUnidad ? 1 : 0.1} step={porUnidad ? 1 : 0.1} max="999"
                  inputMode={porUnidad ? 'numeric' : 'decimal'} value={cantidad} onChange={(e) => setCantidad(e.target.value)}
                  aria-invalid={Boolean(errorCantidad)} aria-describedby={errorCantidad ? 'cantidad-error' : undefined} />
              </div>
              <Boton onClick={alAgregar} cargando={estado.enviando}>Agregar al carrito</Boton>
            </div>
          )}
          {!puedeComprar && <ComprarComoCliente />}
          {errorCantidad && <p id="cantidad-error" className="producto-detalle__error" role="alert">{errorCantidad}</p>}
          {estado.mensaje && (
            <Alerta tipo={estado.tipo}>
              {estado.mensaje} {estado.tipo === 'exito' && <Link to="/carrito">Ver carrito</Link>}
            </Alerta>
          )}
        </section>
      </div>

      <section className="producto-detalle__traza" aria-labelledby="titulo-traza">
        <h2 id="titulo-traza">Origen del producto</h2>
        <p className="producto-detalle__nota">De dónde viene lo que compras: comunidad, productor, producción y lote.</p>

        {producto.trazabilidad.length === 0 ? (
          <p>Todavía no hay lotes registrados de este producto.</p>
        ) : (
          <ol className="traza">
            {producto.trazabilidad.map((lote) => (
              <li key={lote.lote_feder} className="traza__lote">
                <div className="traza__paso"><MapPin size={18} aria-hidden="true" />
                  <span><strong>{lote.comunidad}</strong> · {lote.municipio}, {lote.departamento}</span></div>
                <div className="traza__paso"><Sprout size={18} aria-hidden="true" />
                  <span>Productor: <strong>{lote.productor}</strong></span></div>
                <div className="traza__paso"><Factory size={18} aria-hidden="true" />
                  <span>Lote {lote.lote_productor} · elaborado {formatearFecha(lote.fecha_elaboracion)}
                    {lote.fecha_vencimiento && ` · vence ${formatearFecha(lote.fecha_vencimiento)}`}</span></div>
              </li>
            ))}
          </ol>
        )}
      </section>
    </div>
  )
}
