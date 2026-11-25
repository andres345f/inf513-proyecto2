import { useState } from 'react'
import { Link } from 'react-router-dom'
import { productoresApi } from '../../api/productoresApi'
import Boton from '../../components/ui/Boton'
import Insignia from '../../components/ui/Insignia'
import TablaDatos from '../../components/plantillas/TablaDatos'
import PlantillaLista from '../../components/plantillas/PlantillaLista'
import { useCargaApi } from '../../hooks/useCargaApi'
import { cantidadConUnidad, tonoEstado } from '../../utils/etiquetas'
import { formatearFecha, formatearMoneda } from '../../utils/formato'
import './Productor.css'

const ETIQUETA_LOTE = { DISPONIBLE: 'Disponible', AGOTADO: 'Agotado', RETIRADO: 'Retirado' }

const columnas = [
  { clave: 'codigo_lote', etiqueta: 'Lote' },
  { clave: 'producto', etiqueta: 'Producto' },
  { clave: 'estado', etiqueta: 'Estado', alRender: (l) => <Insignia tono={tonoEstado(l.estado) ?? 'info'}>{ETIQUETA_LOTE[l.estado] ?? l.estado}</Insignia> },
  { clave: 'produccion_codigo', etiqueta: 'Producción', alRender: (l) => <Link to={`/productor/producciones/${l.produccion_id}`}>{l.produccion_codigo}</Link> },
  { clave: 'vence', etiqueta: 'Vence', alRender: (l) => l.fecha_vencimiento ? formatearFecha(l.fecha_vencimiento) : '—' },
  { clave: 'ofrecido', etiqueta: 'Ofrecido', alRender: (l) => cantidadConUnidad(l.cantidad_ofrecida, l.unidad_medida) },
  { clave: 'comprometido', etiqueta: 'Comprometido', alRender: (l) => cantidadConUnidad(l.comprometido, l.unidad_medida) },
  { clave: 'precio', etiqueta: 'Precio', alRender: (l) => l.precio_ofrecido == null ? '—' : formatearMoneda(l.precio_ofrecido) },
  { clave: 'acciones', etiqueta: 'Acciones', alRender: (l) => <Link to={`/productor/producciones/${l.produccion_id}`} aria-label={`Gestionar lote ${l.codigo_lote}`}>Gestionar</Link> },
]

/* Listado plano de los lotes del productor autenticado (solo los suyos: los de
   otros productores nunca aparecen; el backend filtra por su usuario). */
export default function MisLotesPagina() {
  const lotes = useCargaApi(() => productoresApi.lotes(), [])
  const [filtro, setFiltro] = useState('TODOS')

  const todos = lotes.datos?.lotes ?? []
  const visibles = filtro === 'TODOS' ? todos : todos.filter((l) => l.estado === filtro)
  const disponibles = todos.filter((l) => l.estado === 'DISPONIBLE').length
  const comprometido = new Map()
  for (const lote of todos) {
    const anterior = comprometido.get(lote.unidad_medida) ?? 0
    comprometido.set(lote.unidad_medida, anterior + Number(lote.comprometido))
  }
  const comprometidoTotal = [...comprometido].map(([unidad, cantidad]) => cantidadConUnidad(cantidad, unidad)).join(' · ')

  const filtros = loteFiltro(filtro, setFiltro, todos)

  return (
    <PlantillaLista
      titulo="Mis lotes"
      explicacion="Cada lote que registraste en tus producciones, con lo ofrecido a FEDER y lo ya comprometido."
      resumen={{
        etiquetaTotal: 'lotes',
        total: visibles.length,
        chips: [
          { etiqueta: 'Disponibles', cantidad: disponibles, activo: filtro === 'DISPONIBLE' },
          { etiqueta: 'Comprometido con FEDER', cantidad: comprometidoTotal, activo: false },
        ],
      }}
      filtros={filtros}
      filtrosAbiertosAlInicio={false}
      cargando={lotes.cargando}
      error={lotes.error}
      vacio={visibles.length > 0 ? null : todos.length === 0
        ? {
            titulo: 'Todavía no tienes lotes',
            mensaje: 'Registra una producción y agrega su primer lote para ofrecerlo a FEDER.',
            accion: <Link to="/productor/producciones" className="boton">Ir a mis producciones</Link>,
          }
        : {
            titulo: 'Ningún lote con ese estado',
            mensaje: 'Prueba con otro filtro o revisa tus producciones.',
            accion: <Boton variante="secundario" onClick={() => setFiltro('TODOS')}>Quitar filtro</Boton>,
          }}
    >
      <TablaDatos etiqueta="Mis lotes" columnas={columnas} filas={visibles} />
    </PlantillaLista>
  )
}

function loteFiltro(valor, cambiar, todos) {
  const conteo = (estado) => todos.filter((l) => l.estado === estado).length
  return (
    <div className="productor__filtro">
      <label>
        Estado del lote
        <select value={valor} onChange={(e) => cambiar(e.target.value)}>
          <option value="TODOS">Todos ({todos.length})</option>
          <option value="DISPONIBLE">Disponibles ({conteo('DISPONIBLE')})</option>
          <option value="AGOTADO">Agotados ({conteo('AGOTADO')})</option>
          <option value="RETIRADO">Retirados ({conteo('RETIRADO')})</option>
        </select>
      </label>
    </div>
  )
}
