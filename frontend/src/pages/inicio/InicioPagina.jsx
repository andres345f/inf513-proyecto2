import { useRef } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, ChevronLeft, ChevronRight } from 'lucide-react'
import ProductoTarjeta from '../../components/comercio/ProductoTarjeta'
import { comercioApi } from '../../api/comercioApi'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useNavegacion } from '../../hooks/useNavegacion'
import { useSesion } from '../../hooks/useSesion'
import { nombreRol } from '../../utils/formato'
import Alerta from '../../components/ui/Alerta'
import './InicioPagina.css'

const RESUMEN = { CLIENTE: '/mi-cuenta', PRODUCTOR: '/productor', PERSONAL_FEDER: '/operaciones', ADMINISTRADOR: '/administracion' }

export default function InicioPagina() {
  const { sesion, cargando } = useSesion()
  if (cargando) return <p role="status">Cargando inicio…</p>
  return sesion ? <InicioUsuario sesion={sesion} /> : <InicioVisitante />
}

function InicioUsuario({ sesion }) {
  const { entradas, cargando, error } = useNavegacion()
  const destino = entradas.find((e) => e.ruta === RESUMEN[sesion.rol_activo.codigo])
    ?? entradas.find((e) => e.ruta !== '/')
  return <section className="inicio inicio--cuenta">
    <h1>{nombreRol(sesion.rol_activo.codigo)}</h1>
    {cargando ? <p role="status">Cargando opciones…</p> : error ? <Alerta tipo="error">{error}</Alerta>
      : destino ? <Link className="inicio__accion" to={destino.ruta}>{destino.etiqueta} <ArrowRight size={18} /></Link>
        : <p>Tu contexto actual no tiene otras opciones disponibles.</p>}
  </section>
}

function InicioVisitante() {
  const productos = useCargaApi(() => comercioApi.catalogo({}), [])
  const lista = productos.error ? [] : (productos.datos?.productos ?? []).slice(0, 12)
  const pista = useRef(null)
  const mover = (sentido) => pista.current?.scrollBy({ left: sentido * pista.current.clientWidth * 0.8, behavior: 'smooth' })
  return <div className="inicio">
    <section className="inicio__portada" style={{ backgroundImage: `url(${import.meta.env.BASE_URL}marca/portada-comunidad.png)` }}>
      <div className="inicio__texto">
        <h1>Productos de nuestras comunidades</h1>
        <p>Explora los productos de las comunidades afiliadas a FEDER. Consulta su disponibilidad y conoce su origen antes de comprar.</p>
        <Link to="/catalogo" className="inicio__accion">Ver catálogo <ArrowRight size={18} /></Link>
      </div>
    </section>
    <section aria-labelledby="productos-inicio">
      <div className="inicio__cabecera">
        <h2 id="productos-inicio">Productos de nuestras comunidades</h2>
        <Link to="/catalogo" className="inicio__vermas">Ver todo <ArrowRight size={16} aria-hidden="true" /></Link>
      </div>
      {productos.cargando && !productos.datos && <p role="status">Cargando productos…</p>}
      {productos.error && <Alerta tipo="error">{productos.error} <button onClick={productos.recargar}>Reintentar</button></Alerta>}
      {productos.datos && (lista.length ? <div className="inicio__carrusel">
        <button type="button" className="inicio__flecha" aria-label="Productos anteriores" onClick={() => mover(-1)}><ChevronLeft size={20} aria-hidden="true" /></button>
        <ul className="inicio__pista" ref={pista} tabIndex={0} aria-label="Productos del catálogo">
          {lista.map((p) => <li key={p.id}><ProductoTarjeta producto={p} puedeComprar={false} /></li>)}
        </ul>
        <button type="button" className="inicio__flecha" aria-label="Productos siguientes" onClick={() => mover(1)}><ChevronRight size={20} aria-hidden="true" /></button>
      </div> : <p>Aún no hay productos publicados.</p>)}
    </section>
  </div>
}
